<?php

declare(strict_types=1);

namespace justinholtweb\reportr\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * `php craft reportr/reports/…`
 *
 * The command a cron entry calls. Two things about it are deliberate answers to how the plugin
 * this replaces was used:
 *
 * - **`--report` takes a handle**, not just an ID. Lab Reports' command was
 *   `--reportId=43248`, so every crontab on every server carried a number that meant nothing to
 *   a reader and was different on staging. `--reportId` still works, because those crontabs
 *   exist.
 * - **It builds in the foreground by default.** A cron entry that queues a job and exits looks
 *   like it succeeded whether or not the report was ever built — and on a site with no queue
 *   runner it never is. `--queue` is there for sites that do run one.
 */
class ReportsController extends Controller
{
    /** A report handle or ID. */
    public ?string $report = null;

    /** Lab Reports' option name for the same thing, so old cron entries keep working. */
    public ?string $reportId = null;

    /** Parameter answers, as JSON: `--params='{"since":"-7 days"}'`. */
    public ?string $params = null;

    /** Override the report's export format for this run only. */
    public ?string $format = null;

    /** Queue the build instead of running it here. */
    public bool $queue = false;

    /** Rows to show. `preview` only. */
    public ?string $rows = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'build' => ['report', 'reportId', 'params', 'format', 'queue'],
            'preview' => ['report', 'params', 'rows'],
            default => [],
        });
    }

    public function optionAliases(): array
    {
        return ['r' => 'report', 'p' => 'params'];
    }

    /** List every report, with its handle, type and schedule. */
    public function actionIndex(): int
    {
        $reports = Plugin::getInstance()->reports->getAllReports();

        if ($reports === []) {
            $this->stdout("No reports are configured.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            "%-28s %-10s %-7s %-24s %s\n",
            'HANDLE',
            'TYPE',
            'FORMAT',
            'SCHEDULE',
            'TITLE',
        ), Console::BOLD);

        foreach ($reports as $report) {
            $problem = $report->getBlockingProblem();

            $this->stdout(sprintf(
                "%-28s %-10s %-7s %-24s %s\n",
                $report->handle,
                $report->type,
                $report->format,
                $report->getSchedule()->getIsEnabled() ? $report->getSchedule()->describe() : '—',
                $report->title,
            ), $problem !== null ? Console::FG_YELLOW : Console::FG_GREY);

            if ($problem !== null) {
                $this->stdout("    ↳ {$problem}\n", Console::FG_YELLOW);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Build a report.
     *
     * ```
     * php craft reportr/reports/build --report=monthly-orders
     * php craft reportr/reports/build --report=orders --params='{"since":"-30 days"}'
     * ```
     */
    public function actionBuild(): int
    {
        $report = $this->resolveReport();

        if ($report === null) {
            return ExitCode::UNAVAILABLE;
        }

        $params = $this->decodeParams();

        if ($params === null) {
            return ExitCode::DATAERR;
        }

        $plugin = Plugin::getInstance();

        if ($this->format !== null) {
            if (!$plugin->formats->isSupported($this->format)) {
                $this->stderr("The “{$this->format}” format is not available on this server.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            // Changed in memory only — a `--format` on one cron run must not silently rewrite
            // what the report does for everybody else.
            $report->format = $this->format;
        }

        $problem = $report->getBlockingProblem();

        if ($problem !== null) {
            $this->stderr("Cannot run “{$report->handle}”: {$problem}\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $errors = $plugin->reports->validateParams($report, $plugin->reports->normalizeParams($report, $params));

        if ($errors !== []) {
            foreach ($errors as $message) {
                $this->stderr("  {$message}\n", Console::FG_RED);
            }

            return ExitCode::DATAERR;
        }

        if ($this->queue) {
            $run = $plugin->runner->enqueue($report, $params, Run::INITIATOR_CONSOLE);
            $this->stdout("Queued run {$run->id}.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout("Building “{$report->title}”…\n");

        $lastLabel = '';

        try {
            $run = $plugin->runner->run(
                $report,
                $params,
                Run::INITIATOR_CONSOLE,
                null,
                function(float $progress, string $label) use (&$lastLabel): void {
                    if ($label !== $lastLabel) {
                        $lastLabel = $label;
                        // Carriage return rather than a new line: a report of a million rows
                        // should not leave twenty thousand lines in a cron mail.
                        $this->stdout("\r  {$label}          ");
                    }
                },
            );
        } catch (Throwable $e) {
            $this->stderr("\n" . $e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\r" . str_repeat(' ', 40) . "\r");

        if (!$run->getIsFinished()) {
            $this->stderr("Failed: {$run->statusMessage}\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(sprintf(
            "Wrote %s rows to %s (%s) in %s.\n",
            number_format($run->totalRows),
            $run->filename,
            $run->getFormattedSize(),
            $run->getFormattedDuration(),
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** Run a report with a row cap and print what came out. Writes nothing. */
    public function actionPreview(): int
    {
        $report = $this->resolveReport();

        if ($report === null) {
            return ExitCode::UNAVAILABLE;
        }

        $params = $this->decodeParams();

        if ($params === null) {
            return ExitCode::DATAERR;
        }

        $rows = $this->rows !== null && ctype_digit($this->rows) ? (int)$this->rows : null;
        $result = Plugin::getInstance()->runner->preview($report, $params, $rows);

        if (!$result->getSucceeded()) {
            $this->stderr($result->error . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($result->headings !== []) {
            $this->stdout(implode(' | ', $result->headings) . "\n", Console::BOLD);
        }

        foreach ($result->rows as $row) {
            $this->stdout(implode(' | ', array_map(static fn($value) => (string)$value, $row)) . "\n");
        }

        $this->stdout(sprintf(
            "\n%d rows%s in %d ms.\n",
            $result->totalRows,
            $result->truncated ? ' (truncated)' : '',
            $result->durationMs,
        ), Console::FG_GREY);

        return ExitCode::OK;
    }

    // Plumbing
    // -------------------------------------------------------------------------

    private function resolveReport(): ?\justinholtweb\reportr\elements\Report
    {
        $identifier = $this->report ?? $this->reportId;

        if ($identifier === null || $identifier === '') {
            $this->stderr("Which report? Pass --report=<handle>. `reportr/reports` lists them.\n", Console::FG_RED);

            return null;
        }

        $report = Plugin::getInstance()->reports->getReport($identifier);

        if ($report === null) {
            $this->stderr("No report with the handle or ID “{$identifier}”.\n", Console::FG_RED);
        }

        return $report;
    }

    /** @return array<string, mixed>|null Null means the JSON was unusable and it has been reported. */
    private function decodeParams(): ?array
    {
        if ($this->params === null || trim($this->params) === '') {
            return [];
        }

        $decoded = Json::decodeIfJson($this->params);

        if (!is_array($decoded)) {
            // A shell that ate the quotes is the usual cause, and the error message should say so
            // rather than "syntax error" — the JSON is almost never the problem.
            $this->stderr(
                "--params must be a JSON object, e.g. --params='{\"since\":\"-7 days\"}'.\n"
                . "Single quotes around it, or the shell will take the double quotes for itself.\n",
                Console::FG_RED,
            );

            return null;
        }

        return $decoded;
    }
}
