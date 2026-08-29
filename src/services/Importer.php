<?php

declare(strict_types=1);

namespace justinholtweb\reportr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use justinholtweb\reportr\elements\Report;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\models\ImportSummary;
use justinholtweb\reportr\Plugin;
use Throwable;

/**
 * Brings a site's Lab Reports configuration across.
 *
 * ## What actually has to move
 *
 * Less than it looks. The report *templates* do not move at all — Reportr's basic and advanced
 * types call the same methods on the same variable name, so a template written for Lab Reports
 * runs unchanged. The PHP formatting functions do not move either, because Reportr reads
 * `config/labreports.php` as well as its own unless told not to.
 *
 * What moves is the configuration rows and, optionally, the history: which reports exist, what
 * type each is, which template and function it uses, and every report that was ever generated —
 * with its file, if the file is still there.
 *
 * ## Idempotent, because nobody gets it right first time
 *
 * Every imported record keeps the ID it came from in `legacyId`, and anything already carrying
 * that ID is skipped. So the import can be run against a staging copy, checked, and run again on
 * production without producing two of everything — and a run that fails halfway can simply be
 * repeated.
 *
 * The import never modifies the Lab Reports tables. Uninstalling that plugin afterwards is the
 * site's decision, made after looking at the result rather than before.
 */
class Importer extends Component
{
    public const LEGACY_REPORTS_TABLE = '{{%labreports_configured_reports}}';
    public const LEGACY_RUNS_TABLE = '{{%labreports_reports}}';

    /** Whether there is anything here to import from. */
    public function isAvailable(): bool
    {
        return Craft::$app->getDb()->tableExists(self::LEGACY_REPORTS_TABLE);
    }

    /** @return array{reports: int, runs: int, imported: int} */
    public function counts(): array
    {
        if (!$this->isAvailable()) {
            return ['reports' => 0, 'runs' => 0, 'imported' => 0];
        }

        return [
            'reports' => (int)$this->legacyReportQuery()->count(),
            'runs' => Craft::$app->getDb()->tableExists(self::LEGACY_RUNS_TABLE)
                ? (int)$this->legacyRunQuery()->count()
                : 0,
            'imported' => (int)Report::find()->status(null)->andWhere(['not', ['reportr_reports.legacyId' => null]])->count(),
        ];
    }

    /**
     * @param bool $dryRun Report what would happen and change nothing.
     * @param bool $includeRuns Bring the history across as well as the configuration.
     * @param bool $copyFiles Copy each generated file into Reportr's storage.
     */
    public function import(bool $dryRun = false, bool $includeRuns = true, bool $copyFiles = true): ImportSummary
    {
        $summary = new ImportSummary(['dryRun' => $dryRun]);

        if (!$this->isAvailable()) {
            $summary->warn(Craft::t('reportr', 'No Lab Reports tables were found in this database.'));

            return $summary;
        }

        $map = $this->importReports($summary, $dryRun);

        if ($includeRuns && Craft::$app->getDb()->tableExists(self::LEGACY_RUNS_TABLE)) {
            $this->importRuns($summary, $map, $dryRun, $copyFiles);
        }

        return $summary;
    }

    /** @return array<int, int> Legacy report ID => Reportr report ID. */
    private function importReports(ImportSummary $summary, bool $dryRun): array
    {
        $elements = Craft::$app->getElements();
        $map = [];

        foreach ($this->legacyReportQuery()->all() as $row) {
            $summary->reportsFound++;
            $legacyId = (int)$row['id'];

            $existing = Report::find()->status(null)->legacyId($legacyId)->one();

            if ($existing !== null) {
                /** @var Report $existing */
                $map[$legacyId] = (int)$existing->id;
                $summary->reportsSkipped++;
                $summary->note(Craft::t('reportr', 'Skipped “{title}” — already imported.', ['title' => $row['reportTitle'] ?: $legacyId]));

                continue;
            }

            $report = new Report();
            $report->title = (string)($row['reportTitle'] ?: Craft::t('reportr', 'Imported report {id}', ['id' => $legacyId]));
            $report->handle = $this->uniqueHandle((string)$report->title, $legacyId);

            // Lab Reports has exactly two types and both are template types. A value it never
            // wrote is treated as basic rather than rejected — a report that imports as the wrong
            // type can be changed in a select; one that fails to import cannot.
            $report->type = ($row['reportType'] ?? '') === 'advanced' ? Report::TYPE_ADVANCED : Report::TYPE_BASIC;
            $report->description = $row['reportDescription'] ?: null;
            $report->template = $row['template'] ?: null;
            $report->formatFunction = $row['formatFunction'] ?: null;
            $report->format = 'csv';
            $report->legacyId = $legacyId;

            // The old plugin's filenames were `<slug-of-title>-<YmdHis>.csv`. Reproducing the
            // pattern means anything downstream that matched on those names keeps matching.
            $report->filenameFormat = '{title}-{datetime}';

            if ($dryRun) {
                $summary->reportsImported++;
                $summary->note(Craft::t('reportr', 'Would import “{title}” ({type}).', [
                    'title' => $report->title,
                    'type' => $report->getTypeLabel(),
                ]));

                continue;
            }

            if (!$elements->saveElement($report)) {
                $summary->warn(Craft::t('reportr', 'Could not import “{title}”: {errors}', [
                    'title' => $report->title,
                    'errors' => implode(' ', array_map(
                        static fn(array $messages) => implode(' ', $messages),
                        $report->getErrors(),
                    )),
                ]));

                continue;
            }

            $map[$legacyId] = (int)$report->id;
            $summary->reportsImported++;
            $summary->note(Craft::t('reportr', 'Imported “{title}” as {handle}.', [
                'title' => $report->title,
                'handle' => $report->handle,
            ]));
        }

        return $map;
    }

    /** @param array<int, int> $map */
    private function importRuns(ImportSummary $summary, array $map, bool $dryRun, bool $copyFiles): void
    {
        $elements = Craft::$app->getElements();
        $legacyFolder = $this->legacyStorageFolder();

        foreach ($this->legacyRunQuery()->all() as $row) {
            $summary->runsFound++;
            $legacyId = (int)$row['id'];

            if (Run::find()->status(null)->legacyId($legacyId)->exists()) {
                $summary->runsSkipped++;

                continue;
            }

            $reportId = $map[(int)($row['configuredReportId'] ?? 0)] ?? null;

            $run = new Run();
            $run->reportId = $reportId;
            $run->legacyId = $legacyId;
            $run->filename = $row['filename'] ?: null;
            $run->path = $row['filename'] ?: null;
            $run->format = $this->formatFromFilename((string)$row['filename']);
            $run->totalRows = (int)($row['totalRows'] ?? 0);
            $run->userId = $row['userId'] ? (int)$row['userId'] : null;
            $run->initiator = Run::INITIATOR_CP;
            $run->runStatus = $this->mapStatus((string)($row['reportStatus'] ?? ''));
            $run->statusMessage = $row['statusMessage'] ?: null;

            // Lab Reports left a run at `in_progress` whenever the build died — a fatal, a
            // timeout, a killed worker. Importing that as "still running" would leave a spinner
            // on the index for ever, so it comes across as failed with the reason spelled out.
            if (($row['reportStatus'] ?? '') === 'in_progress' && $run->statusMessage === null) {
                $run->statusMessage = Craft::t('reportr', 'This run was still marked “in progress” in Lab Reports when it was imported, which means it never finished.');
            }

            if ($reportId !== null) {
                /** @var Report|null $report */
                $report = Report::find()->status(null)->id($reportId)->one();
                $run->reportTitle = $report?->title;
            }

            $generated = $row['dateGenerated'] ?: ($row['dateCreated'] ?? null);

            if ($generated) {
                $date = DateTimeHelper::toDateTime($generated, false, false);
                $run->dateFinished = $date !== false ? $date : null;
                $run->dateStarted = $run->dateFinished;
            }

            if ($dryRun) {
                $summary->runsImported++;

                continue;
            }

            if (!$elements->saveElement($run, false, false, false)) {
                $summary->warn(Craft::t('reportr', 'Could not import run {id}.', ['id' => $legacyId]));

                continue;
            }

            // The element's `dateCreated` is stamped by Craft at save time, which would make every
            // imported run look like it happened today and destroy the one thing the history is
            // for. Put the real date back.
            if ($run->dateFinished !== null) {
                Craft::$app->getDb()->createCommand()
                    ->update(\craft\db\Table::ELEMENTS, [
                        'dateCreated' => Db::prepareDateForDb($run->dateFinished),
                    ], ['id' => $run->id])
                    ->execute();

                Craft::$app->getDb()->createCommand()
                    ->update(\justinholtweb\reportr\records\Table::RUNS, [
                        'dateCreated' => Db::prepareDateForDb($run->dateFinished),
                    ], ['id' => $run->id])
                    ->execute();
            }

            $summary->runsImported++;

            if ($copyFiles && $run->filename) {
                $this->copyFile($summary, $run, $legacyFolder);
            }
        }
    }

    private function copyFile(ImportSummary $summary, Run $run, ?string $legacyFolder): void
    {
        if ($legacyFolder === null) {
            $summary->filesMissing++;

            return;
        }

        $source = $legacyFolder . DIRECTORY_SEPARATOR . $run->filename;

        if (!is_file($source)) {
            $summary->filesMissing++;

            return;
        }

        try {
            // Copied, never moved. The old plugin is still installed at this point and a report
            // that vanishes from its control panel mid-migration is alarming for no reason.
            $temp = Plugin::getInstance()->storage->tempPath((string)$run->filename);

            if (!copy($source, $temp)) {
                $summary->filesMissing++;

                return;
            }

            Plugin::getInstance()->storage->store($run, $temp);
            Craft::$app->getElements()->saveElement($run, false, false, false);
            $summary->filesCopied++;
        } catch (Throwable $e) {
            $summary->warn(Craft::t('reportr', 'Could not copy {filename}: {message}', [
                'filename' => (string)$run->filename,
                'message' => $e->getMessage(),
            ]));
            $summary->filesMissing++;
        }
    }

    // Reading the old plugin
    // -------------------------------------------------------------------------

    private function legacyReportQuery(): Query
    {
        return (new Query())
            ->select([
                'lr.id',
                'lr.reportType',
                'lr.reportTitle',
                'lr.reportDescription',
                'lr.template',
                'lr.formatFunction',
                'lr.dateCreated',
            ])
            ->from(['lr' => self::LEGACY_REPORTS_TABLE])
            // Joined to `elements` so that reports sitting in Craft's trash are not resurrected
            // by the migration — somebody deleted those on purpose.
            ->innerJoin(['e' => \craft\db\Table::ELEMENTS], '[[e.id]] = [[lr.id]]')
            ->where(['e.dateDeleted' => null])
            ->orderBy(['lr.id' => SORT_ASC]);
    }

    private function legacyRunQuery(): Query
    {
        return (new Query())
            ->select([
                'lr.id',
                'lr.configuredReportId',
                'lr.reportStatus',
                'lr.statusMessage',
                'lr.filename',
                'lr.totalRows',
                'lr.userId',
                'lr.dateGenerated',
                'lr.dateCreated',
            ])
            ->from(['lr' => self::LEGACY_RUNS_TABLE])
            ->innerJoin(['e' => \craft\db\Table::ELEMENTS], '[[e.id]] = [[lr.id]]')
            ->where(['e.dateDeleted' => null])
            ->orderBy(['lr.id' => SORT_ASC]);
    }

    /**
     * Where Lab Reports kept its files.
     *
     * Its setting was never written to project config on most installs — the default was computed
     * in the model's `init()` — so the config file is checked first and the documented default
     * second. A wrong guess here costs the file copy, not the import.
     */
    public function legacyStorageFolder(): ?string
    {
        $fromConfig = Craft::$app->getConfig()->getConfigFromFile('labreports')['fileStorageFolder'] ?? '';

        if (is_string($fromConfig) && trim($fromConfig) !== '') {
            return rtrim(trim($fromConfig), '/\\');
        }

        $fromProjectConfig = Craft::$app->getProjectConfig()->get('plugins.labreports.settings.fileStorageFolder');

        if (is_string($fromProjectConfig) && trim($fromProjectConfig) !== '') {
            return rtrim(trim($fromProjectConfig), '/\\');
        }

        $default = Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . 'labreports';

        return is_dir($default) ? $default : null;
    }

    private function mapStatus(string $legacy): string
    {
        return match ($legacy) {
            'finished' => Run::STATUS_FINISHED,
            'in_progress' => Run::STATUS_ERROR,
            'error' => Run::STATUS_ERROR,
            default => Run::STATUS_FINISHED,
        };
    }

    private function formatFromFilename(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return Plugin::getInstance()->formats->has($extension) ? $extension : 'csv';
    }

    private function uniqueHandle(string $title, int $legacyId): string
    {
        $base = StringHelper::slugify($title) ?: ('report-' . $legacyId);
        $handle = mb_substr($base, 0, 64);
        $suffix = 1;

        while (Report::find()->handle($handle)->status(null)->exists()) {
            $suffix++;
            $handle = mb_substr($base, 0, 60) . '-' . $suffix;
        }

        return $handle;
    }
}
