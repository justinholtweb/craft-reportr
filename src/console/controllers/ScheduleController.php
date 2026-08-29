<?php

declare(strict_types=1);

namespace justinholtweb\reportr\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\reportr\Plugin;
use yii\console\ExitCode;

/**
 * `php craft reportr/schedule/…`
 *
 * The cron entry a scheduled site wants:
 *
 * ```
 * * * * * * cd /path/to/project && php craft reportr/schedule/run
 * ```
 *
 * One minute is right even though most minutes do nothing: the check is a single indexed
 * comparison against a stored timestamp, and a coarser interval means "07:00" quietly becomes
 * "some time in the 07:00 hour".
 */
class ScheduleController extends Controller
{
    /** Build due reports here instead of queueing them. */
    public bool $inline = false;

    /** Show what is due and change nothing. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'run' => ['inline', 'dryRun'],
            default => [],
        });
    }

    /** List every scheduled report and when it next runs. */
    public function actionIndex(): int
    {
        $reports = Plugin::getInstance()->reports->getAllReports();
        $scheduled = array_filter($reports, static fn($report) => $report->getSchedule()->getIsEnabled());

        if ($scheduled === []) {
            $this->stdout("No reports are scheduled.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $formatter = Craft::$app->getFormatter();

        $this->stdout(sprintf("%-28s %-30s %-22s %s\n", 'HANDLE', 'SCHEDULE', 'NEXT RUN', 'STATE'), Console::BOLD);

        foreach ($scheduled as $report) {
            $this->stdout(sprintf(
                "%-28s %-30s %-22s %s\n",
                $report->handle,
                $report->getSchedule()->describe(),
                $report->nextRunAt !== null ? $formatter->asDatetime($report->nextRunAt, 'short') : '—',
                $report->enabled ? 'enabled' : 'disabled',
            ));
        }

        return ExitCode::OK;
    }

    /** Run everything that is due. */
    public function actionRun(): int
    {
        $schedules = Plugin::getInstance()->schedules;

        if ($this->dryRun) {
            $due = $schedules->due();

            if ($due === []) {
                $this->stdout("Nothing is due.\n");

                return ExitCode::OK;
            }

            foreach ($due as $report) {
                $this->stdout("  {$report->handle}  {$report->title}\n");
            }

            $this->stdout(sprintf("\n%d would run. Nothing was changed.\n", count($due)), Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $runs = $schedules->runDue(null, $this->inline);

        if ($runs === []) {
            // Silent on the common path: this runs every minute, and a cron entry that emails
            // "nothing to do" 1,440 times a day gets filtered and then ignored entirely.
            return ExitCode::OK;
        }

        foreach ($runs as $run) {
            $this->stdout(sprintf(
                "%s — %s\n",
                $run->reportTitle,
                $run->getIsFinished()
                    ? number_format($run->totalRows) . ' rows'
                    : $run->getStatusLabel(),
            ), $run->runStatus === 'error' ? Console::FG_RED : Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    /**
     * Recompute every stored next-run time.
     *
     * After a timezone change, or a database restored from another machine — both of which
     * otherwise leave reports firing at the wrong hour while the control panel confidently shows
     * the right one.
     */
    public function actionRefresh(): int
    {
        $count = Plugin::getInstance()->schedules->refreshAll();

        $this->stdout("Recalculated {$count} schedules.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
