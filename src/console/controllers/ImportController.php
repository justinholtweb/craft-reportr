<?php

declare(strict_types=1);

namespace justinholtweb\reportr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\reportr\Plugin;
use yii\console\ExitCode;

/**
 * `php craft reportr/import/lab-reports`
 *
 * Brings a site's Lab Reports configuration across. Safe to run twice — everything imported
 * keeps the ID it came from, and anything already carrying that ID is skipped — so the sensible
 * sequence is a dry run, then a real one, then a look at the reports list.
 */
class ImportController extends Controller
{
    public $defaultAction = 'lab-reports';

    /** Report what would happen and change nothing. */
    public bool $dryRun = false;

    /** Skip the generated-report history and bring only the configurations. */
    public bool $withoutRuns = false;

    /** Skip copying the generated files. */
    public bool $withoutFiles = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'lab-reports' => ['dryRun', 'withoutRuns', 'withoutFiles'],
            default => [],
        });
    }

    public function actionLabReports(): int
    {
        $importer = Plugin::getInstance()->importer;

        if (!$importer->isAvailable()) {
            $this->stderr("No Lab Reports tables were found in this database.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $counts = $importer->counts();
        $this->stdout(sprintf(
            "Found %d configured reports and %d generated reports.\n\n",
            $counts['reports'],
            $counts['runs'],
        ));

        $summary = $importer->import($this->dryRun, !$this->withoutRuns, !$this->withoutFiles);

        foreach ($summary->log as $line) {
            $this->stdout('  ' . $line . "\n");
        }

        foreach ($summary->warnings as $warning) {
            $this->stderr('  ! ' . $warning . "\n", Console::FG_YELLOW);
        }

        $this->stdout(sprintf(
            "\n%s %d reports (%d skipped), %d runs (%d skipped), %d files copied, %d files missing.\n",
            $this->dryRun ? 'Would import' : 'Imported',
            $summary->reportsImported,
            $summary->reportsSkipped,
            $summary->runsImported,
            $summary->runsSkipped,
            $summary->filesCopied,
            $summary->filesMissing,
        ), $this->dryRun ? Console::FG_YELLOW : Console::FG_GREEN);

        if ($this->dryRun) {
            $this->stdout("Nothing was changed. Run again without --dry-run to import.\n", Console::FG_YELLOW);
        } else {
            $this->stdout(
                "\nYour report templates need no changes. Advanced reports keep using the formatting\n"
                . "functions in config/labreports.php unless you turn that off in Reportr’s settings.\n",
                Console::FG_GREY,
            );
        }

        return ExitCode::OK;
    }
}
