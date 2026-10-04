<?php

declare(strict_types=1);

namespace justinholtweb\reportr\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\FileHelper;
use craft\helpers\Queue as QueueHelper;
use craft\web\View;
use DateTime;
use justinholtweb\reportr\build\PreviewResult;
use justinholtweb\reportr\build\Session;
use justinholtweb\reportr\elements\Report;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\events\RunEvent;
use justinholtweb\reportr\Plugin;
use justinholtweb\reportr\queue\jobs\BuildReport;
use Throwable;

/**
 * Runs reports.
 *
 * Everything that can produce a report file goes through `execute()` — the control panel, the
 * console command, the scheduler and a Twig call all end up here — so there is exactly one place
 * where a report's output is decided. A second code path is how a plugin ends up with a report
 * that behaves differently on a schedule than it does when a person presses the button, which is
 * the hardest class of bug to be told about.
 */
class Runner extends Component
{
    /** Raised before a run starts. Cancellable: set `$event->isValid = false` to stop it. */
    public const EVENT_BEFORE_RUN = 'beforeRun';

    /** Raised after a run finishes, successfully or not. */
    public const EVENT_AFTER_RUN = 'afterRun';

    /** Runs deleted inline by the retention sweep before it gives up and leaves the rest to GC. */
    private const RETENTION_BATCH = 100;

    /**
     * Create a queued run and push the job that will build it.
     *
     * The run element exists *before* the job does, on purpose: the control panel can then link
     * straight to it and show "Queued", instead of a flash message and a list that has not
     * changed yet.
     */
    public function enqueue(
        Report $report,
        array $rawParams = [],
        string $initiator = Run::INITIATOR_CP,
        ?int $userId = null,
    ): Run {
        $run = $this->createRun($report, $rawParams, $initiator, $userId);

        QueueHelper::push(new BuildReport([
            'runId' => (int)$run->id,
            'reportTitle' => (string)$report->title,
        ]), ttr: Plugin::getInstance()->getSettings()->jobTtr);

        return $run;
    }

    /** Build a report here and now, without the queue. */
    public function run(
        Report $report,
        array $rawParams = [],
        string $initiator = Run::INITIATOR_CONSOLE,
        ?int $userId = null,
        ?callable $progress = null,
    ): Run {
        $run = $this->createRun($report, $rawParams, $initiator, $userId);

        return $this->execute($run, $progress);
    }

    private function createRun(Report $report, array $rawParams, string $initiator, ?int $userId): Run
    {
        $reports = Plugin::getInstance()->reports;
        $normalized = $reports->normalizeParams($report, $rawParams);

        $run = new Run();
        $run->setReport($report);
        $run->runStatus = Run::STATUS_QUEUED;
        $run->initiator = $initiator;
        $run->userId = $userId ?? Craft::$app->getUser()->getIdentity()?->id;
        $run->format = $report->format;
        $run->setParams($reports->serializeParams($report, $normalized));

        Craft::$app->getElements()->saveElement($run, false, false, false);

        return $run;
    }

    /**
     * Build the file for a run that already exists.
     *
     * Never throws. A report that fails has to come back as a *run with a reason on it* — the
     * whole point of recording runs is that somebody can find out why last Tuesday's export was
     * empty, and an exception that escapes into the queue log is not that.
     */
    public function execute(Run $run, ?callable $progress = null): Run
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $report = $run->getReport();

        if ($report === null) {
            $run->updateStatus(Run::STATUS_ERROR, Craft::t('reportr', 'The report configuration no longer exists.'));

            return $run;
        }

        $event = new RunEvent(['report' => $report, 'run' => $run]);
        $this->trigger(self::EVENT_BEFORE_RUN, $event);

        if (!$event->isValid) {
            $run->updateStatus(Run::STATUS_ERROR, Craft::t('reportr', 'The run was cancelled before it started.'));

            return $run;
        }

        $problem = $report->getBlockingProblem();

        if ($problem !== null) {
            $run->updateStatus(Run::STATUS_ERROR, $problem);
            $this->afterRun($report, $run);

            return $run;
        }

        $params = $plugin->reports->normalizeParams($report, $run->getParams());

        // The control panel and `reports/build` check the answers before queueing; a schedule or
        // `craft.reportr.queue()` does not, and a required parameter with no answer and no default
        // would otherwise reach the template as null and produce a quietly wrong file.
        $paramErrors = $plugin->reports->validateParams($report, $params);

        if ($paramErrors !== []) {
            $run->updateStatus(Run::STATUS_ERROR, implode("\n", $paramErrors));
            $this->afterRun($report, $run);

            return $run;
        }

        $started = microtime(true);
        $run->dateStarted = DateTimeHelper::currentUTCDateTime();
        $run->updateStatus(Run::STATUS_RUNNING);

        $restoreMemory = $this->raiseLimits($settings->memoryLimit, $settings->timeLimit);

        $extension = $plugin->formats->extension($report->format);
        $filename = $plugin->reports->buildFilename($report, $extension, $params);
        $tempPath = $plugin->storage->tempPath($filename);

        $run->filename = $filename;
        $run->format = $report->format;

        $writer = $plugin->formats->create($report->format, $tempPath, $report->getFormatOptions());
        $session = new Session($report, $run, $writer, $params, $progress);
        $run->setSession($session);

        try {
            $session->begin();

            if ($report->type === Report::TYPE_QUERY) {
                $session->buildFromQuerySpec();
            } else {
                $this->renderTemplate($report, $run, $params);
            }

            $size = $session->finish();

            if (!$session->getWasOpened() || !is_file($tempPath)) {
                // A template that renders without ever calling `build()` or `addRow()` is the
                // single most common mistake in this kind of plugin, and "Unavailable (File
                // Missing)" is a terrible way to be told about it.
                throw new \RuntimeException('The report template ran but never wrote any rows. Did you forget `{% do report.build(rows) %}`?');
            }

            $plugin->storage->store($run, $tempPath, $report);

            $run->totalRows = $session->getRowsWritten();
            $run->fileSize ??= $size;
            $run->dateFinished = DateTimeHelper::currentUTCDateTime();
            $run->durationMs = (int)round((microtime(true) - $started) * 1000);
            $run->peakMemory = memory_get_peak_usage(true);
            $run->updateStatus(Run::STATUS_FINISHED);
        } catch (Throwable $e) {
            $session->finish();
            FileHelper::unlink($tempPath);

            $run->dateFinished = DateTimeHelper::currentUTCDateTime();
            $run->durationMs = (int)round((microtime(true) - $started) * 1000);
            $run->peakMemory = memory_get_peak_usage(true);
            $run->updateStatus(Run::STATUS_ERROR, $this->describeError($e));

            Craft::error(sprintf(
                'Reportr failed to build “%s”: %s',
                $report->title,
                $e->getMessage(),
            ), 'reportr');
        } finally {
            $run->setSession(null);
            $restoreMemory();
        }

        $this->afterRun($report, $run);

        return $run;
    }

    /**
     * Render the report template.
     *
     * `setTemplateMode()`, not `setTemplatesPath()`. Lab Reports set the path directly, which
     * loads the right file and leaves Twig's loader pointed at the control-panel namespace — so
     * `{% import %}` and `{% include %}` inside a report template threw "template not found" for
     * files sitting right beside it. That is its issue #4, and the mode is the fix.
     */
    private function renderTemplate(Report $report, Run $run, array $params): void
    {
        $view = Craft::$app->getView();
        $sites = Craft::$app->getSites();

        $oldMode = $view->getTemplateMode();
        $oldSite = $sites->getCurrentSite();

        // A `site` parameter changes which site's content the report reads, which means the
        // *current site* — element queries and `craft.entries` both read it — not just a siteId
        // passed to one query.
        $siteId = null;

        foreach ($report->getParams() as $param) {
            if ($param->type === \justinholtweb\reportr\models\Parameter::TYPE_SITE) {
                $siteId = $params[$param->name] ?? null;
            }
        }

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            if ($siteId !== null) {
                $site = $sites->getSiteById((int)$siteId);

                if ($site !== null) {
                    $sites->setCurrentSite($site);
                }
            }

            $view->renderTemplate((string)$report->template, [
                // `report` is the run, and that is not a slip: it is what every Lab Reports
                // template calls the object it builds rows on, so imported templates work.
                'report' => $run,
                'run' => $run,
                'params' => $params,
                'config' => $report,
            ], View::TEMPLATE_MODE_SITE);
        } finally {
            $view->setTemplateMode($oldMode);
            $sites->setCurrentSite($oldSite);
        }
    }

    /**
     * Run a report without saving anything, and keep the first few rows to show.
     *
     * The preview is the same code path as a real run, deliberately — a preview that used a
     * simplified path would be able to succeed where the real thing fails.
     */
    public function preview(Report $report, array $rawParams = [], ?int $maxRows = null): PreviewResult
    {
        $plugin = Plugin::getInstance();
        $maxRows ??= $plugin->getSettings()->previewRows;

        $problem = $report->getBlockingProblem();

        if ($problem !== null) {
            return PreviewResult::failure($problem);
        }

        $started = microtime(true);
        $params = $plugin->reports->normalizeParams($report, $rawParams);

        $run = new Run();
        $run->setReport($report);
        $run->runStatus = Run::STATUS_RUNNING;
        $run->initiator = Run::INITIATOR_CP;
        $run->setParams($plugin->reports->serializeParams($report, $params));

        $tempPath = $plugin->storage->tempPath('preview-' . $report->handle . '-' . random_int(1000, 9999) . '.tmp');
        $writer = $plugin->formats->create($report->format, $tempPath, $report->getFormatOptions());

        $session = (new Session($report, $run, $writer, $params))
            ->setMaxRows($maxRows)
            ->setCollect(true);

        $run->setSession($session);

        $restoreMemory = $this->raiseLimits($plugin->getSettings()->memoryLimit, min(60, $plugin->getSettings()->timeLimit ?: 60));

        try {
            $session->begin();

            if ($report->type === Report::TYPE_QUERY) {
                $session->buildFromQuerySpec();
            } else {
                $this->renderTemplate($report, $run, $params);
            }

            return new PreviewResult(
                headings: $session->getHeadings(),
                rows: $session->getCollected(),
                totalRows: $session->getRowsWritten(),
                truncated: $session->getReachedLimit(),
                durationMs: (int)round((microtime(true) - $started) * 1000),
            );
        } catch (Throwable $e) {
            return PreviewResult::failure($this->describeError($e));
        } finally {
            $session->finish();
            FileHelper::unlink($tempPath);
            $run->setSession(null);
            $restoreMemory();
        }
    }

    // Afterwards
    // -------------------------------------------------------------------------

    private function afterRun(Report $report, Run $run): void
    {
        $report->lastRunAt = DateTimeHelper::currentUTCDateTime();

        // Two columns, written straight to the row, and **not** `saveElement()`.
        //
        // Saving the element here would persist whatever else is on it in memory — and something
        // always is. `--format=csv` on the console overrides the format for one run; a save at
        // this point makes that override permanent, so a cron entry silently rewrites the report
        // it was only meant to read. The increment is done in SQL as well, so two runs finishing
        // at once cannot both write the same number.
        Craft::$app->getDb()->createCommand()
            ->update(
                \justinholtweb\reportr\records\Table::REPORTS,
                [
                    'runCount' => new \yii\db\Expression('[[runCount]] + 1'),
                    'lastRunAt' => \craft\helpers\Db::prepareDateForDb($report->lastRunAt),
                ],
                ['id' => $report->id],
            )
            ->execute();

        $report->runCount++;

        try {
            Plugin::getInstance()->notifications->sendForRun($run, $report);
        } catch (Throwable $e) {
            Craft::error('Reportr could not send the run notification: ' . $e->getMessage(), 'reportr');
        }

        try {
            $this->applyRetention($report);
        } catch (Throwable $e) {
            Craft::error('Reportr could not apply the retention policy: ' . $e->getMessage(), 'reportr');
        }

        $this->trigger(self::EVENT_AFTER_RUN, new RunEvent(['report' => $report, 'run' => $run]));
    }

    /**
     * Delete runs the report no longer wants to keep.
     *
     * Hard deletes, files and all. A retention policy whose deletions sit in the trash still
     * occupies the disk, which is usually the whole reason somebody set one.
     */
    public function applyRetention(Report $report): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $keepRuns = $report->retentionRuns ?? $settings->retentionRuns;
        $keepDays = $report->retentionDays ?? $settings->retentionDays;

        if ($keepRuns <= 0 && $keepDays <= 0) {
            return 0;
        }

        $elements = Craft::$app->getElements();
        $deleted = 0;

        if ($keepDays > 0) {
            $cutoff = (new DateTime('now'))->modify("-{$keepDays} days");

            /** @var Run[] $old */
            $old = Run::find()
                ->reportId($report->id)
                ->status(null)
                ->andWhere(['<', 'reportr_runs.dateCreated', \craft\helpers\Db::prepareDateForDb($cutoff)])
                ->limit(self::RETENTION_BATCH)
                ->all();

            foreach ($old as $run) {
                $elements->deleteElement($run, true);
                $deleted++;
            }
        }

        if ($keepRuns > 0) {
            /** @var Run[] $surplus */
            $surplus = Run::find()
                ->reportId($report->id)
                ->status(null)
                ->orderBy(['reportr_runs.dateCreated' => SORT_DESC])
                ->offset($keepRuns)
                ->limit(self::RETENTION_BATCH)
                ->all();

            foreach ($surplus as $run) {
                $elements->deleteElement($run, true);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Mark runs that are still "running" long after anything could still be running them.
     *
     * A queue job reclaimed at its TTR, a fatal out-of-memory, a container restarted mid-build:
     * none of those give the runner a chance to write a status, so the run sits at "Running"
     * for ever with a spinner beside it. Without this sweep the only cure is a database edit,
     * which is a poor thing to ask of somebody whose report did not arrive.
     *
     * The cutoff is the job TTR plus an hour, so a legitimately long build is never killed by
     * the sweep that is supposed to be tidying up after the dead ones.
     */
    public function failStalledRuns(): int
    {
        $ttr = Plugin::getInstance()->getSettings()->jobTtr;
        $cutoff = (new DateTime('now'))->modify(sprintf('-%d seconds', $ttr + 3600));

        /** @var Run[] $stalled */
        $stalled = Run::find()
            ->status([Run::STATUS_RUNNING, Run::STATUS_QUEUED])
            ->andWhere(['<', 'reportr_runs.dateCreated', \craft\helpers\Db::prepareDateForDb($cutoff)])
            ->limit(200)
            ->all();

        foreach ($stalled as $run) {
            $run->dateFinished ??= DateTimeHelper::currentUTCDateTime();
            $run->updateStatus(
                Run::STATUS_ERROR,
                Craft::t('reportr', 'This run stopped without finishing — the queue job was reclaimed, or the process was killed. Raising the job timeout in Reportr’s settings is the usual fix.'),
            );
        }

        return count($stalled);
    }

    // Plumbing
    // -------------------------------------------------------------------------

    /**
     * Raise PHP's limits for the duration of a build, and hand back a closure that puts them back.
     *
     * Lab Reports issues #6 and #7 are both this, from opposite ends: a report that outgrew the
     * memory limit and a report that outgrew the time limit, with no setting for either. Raising
     * them *only for the build* matters — a web request that leaves `max_execution_time` at zero
     * afterwards has turned one slow page into a permanently hung worker.
     *
     * @return callable(): void
     */
    private function raiseLimits(string $memoryLimit, int $timeLimit): callable
    {
        $oldMemory = ini_get('memory_limit');
        $oldTime = ini_get('max_execution_time');

        if ($memoryLimit !== '') {
            $wanted = App::phpConfigValueInBytes('memory_limit');
            $requested = App::phpSizeToBytes($memoryLimit);

            // Never *lower* the limit. A server already configured with 2 GB should not be talked
            // down to the plugin's 512 MB default by a setting nobody revisited.
            if ($wanted !== -1 && ($requested === -1 || $requested > $wanted)) {
                @ini_set('memory_limit', $memoryLimit);
            }
        }

        if ($timeLimit > 0) {
            @set_time_limit($timeLimit);
        }

        return static function() use ($oldMemory, $oldTime): void {
            // ini_get() is typed `string` but returns false for an unknown directive.
            if ($oldMemory !== false) { // @phpstan-ignore notIdentical.alwaysTrue
                @ini_set('memory_limit', $oldMemory);
            }

            if ($oldTime !== false) { // @phpstan-ignore notIdentical.alwaysTrue
                @set_time_limit((int)$oldTime);
            }
        };
    }

    /**
     * A failure message worth reading.
     *
     * The class name and the file and line are included because the message alone is frequently
     * "Array to string conversion", and the useful part is which template line did it.
     */
    private function describeError(Throwable $e): string
    {
        $message = sprintf(
            '%s: %s (%s:%d)',
            (new \ReflectionClass($e))->getShortName(),
            $e->getMessage(),
            basename($e->getFile()),
            $e->getLine(),
        );

        if (Plugin::getInstance()->getSettings()->debug) {
            $message .= "\n\n" . $e->getTraceAsString();
        }

        return $message;
    }
}
