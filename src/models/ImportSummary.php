<?php

declare(strict_types=1);

namespace justinholtweb\reportr\models;

use craft\base\Model;

/** What an import did, or would have done. */
class ImportSummary extends Model
{
    public bool $dryRun = false;
    public int $reportsFound = 0;
    public int $reportsImported = 0;
    public int $reportsSkipped = 0;
    public int $runsFound = 0;
    public int $runsImported = 0;
    public int $runsSkipped = 0;
    public int $filesCopied = 0;
    public int $filesMissing = 0;

    /** @var string[] One line per thing that happened, in the order it happened. */
    public array $log = [];

    /** @var string[] Things that went wrong but did not stop the import. */
    public array $warnings = [];

    public function note(string $message): void
    {
        $this->log[] = $message;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function getTotalImported(): int
    {
        return $this->reportsImported + $this->runsImported;
    }
}
