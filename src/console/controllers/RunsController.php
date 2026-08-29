<?php

declare(strict_types=1);

namespace justinholtweb\reportr\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\Plugin;
use yii\console\ExitCode;

/**
 * `php craft reportr/runs/…`
 */
class RunsController extends Controller
{
    /** Limit to one report, by handle or ID. */
    public ?string $report = null;

    /** How many to list. */
    public ?string $limit = null;

    /** Report what would be deleted and delete nothing. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'index' => ['report', 'limit'],
            'prune' => ['report', 'dryRun'],
            default => [],
        });
    }

    /** List recent runs. */
    public function actionIndex(): int
    {
        /** @var \justinholtweb\reportr\elements\db\RunQuery $query */
        $query = Run::find();
        $query->status(null)->limit($this->limit !== null && ctype_digit($this->limit) ? (int)$this->limit : 25);

        if ($this->report !== null) {
            $query->report($this->report);
        }

        $runs = $query->all();

        if ($runs === []) {
            $this->stdout("No runs.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $formatter = Craft::$app->getFormatter();

        $this->stdout(sprintf("%-7s %-10s %-9s %-20s %s\n", 'ID', 'STATUS', 'ROWS', 'FINISHED', 'FILE'), Console::BOLD);

        foreach ($runs as $run) {
            $this->stdout(sprintf(
                "%-7s %-10s %-9s %-20s %s\n",
                (string)$run->id,
                $run->runStatus,
                number_format($run->totalRows),
                $run->dateFinished !== null ? $formatter->asDatetime($run->dateFinished, 'short') : '—',
                (string)$run->filename,
            ), $run->runStatus === Run::STATUS_ERROR ? Console::FG_RED : Console::FG_GREY);

            if ($run->runStatus === Run::STATUS_ERROR && $run->statusMessage) {
                $this->stdout('    ↳ ' . strtok($run->statusMessage, "\n") . "\n", Console::FG_RED);
            }
        }

        return ExitCode::OK;
    }

    /** Apply every report's retention policy now. */
    public function actionPrune(): int
    {
        $plugin = Plugin::getInstance();
        $reports = $this->report !== null
            ? array_filter([$plugin->reports->getReport($this->report)])
            : $plugin->reports->getAllReports();

        if ($reports === []) {
            $this->stderr("No matching reports.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $total = 0;

        foreach ($reports as $report) {
            $keepRuns = $report->retentionRuns ?? $plugin->getSettings()->retentionRuns;
            $keepDays = $report->retentionDays ?? $plugin->getSettings()->retentionDays;

            if ($keepRuns <= 0 && $keepDays <= 0) {
                continue;
            }

            if ($this->dryRun) {
                $kept = (int)Run::find()->reportId($report->id)->status(null)->count();
                $this->stdout(sprintf("%-28s %d runs, keeping %s\n", $report->handle, $kept, $keepRuns ?: 'all'));

                continue;
            }

            $deleted = $plugin->runner->applyRetention($report);
            $total += $deleted;

            if ($deleted > 0) {
                $this->stdout(sprintf("%-28s deleted %d\n", $report->handle, $deleted), Console::FG_GREEN);
            }
        }

        if (!$this->dryRun) {
            $this->stdout("Deleted {$total} runs.\n", Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    /**
     * Mark runs that stopped without finishing.
     *
     * Craft's garbage collection does this too; the command is for a site that would rather not
     * wait, and for finding out how many there are.
     */
    public function actionSweep(): int
    {
        $count = Plugin::getInstance()->runner->failStalledRuns();

        $this->stdout("Marked {$count} stalled runs as failed.\n", $count > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        return ExitCode::OK;
    }
}
