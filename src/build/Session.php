<?php

declare(strict_types=1);

namespace justinholtweb\reportr\build;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use justinholtweb\reportr\elements\Report;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\Plugin;
use justinholtweb\reportr\writers\WriterInterface;
use RuntimeException;

/**
 * One report being built, from the first row to the last.
 *
 * The session owns the writer, the batching and the counters. It exists only while a build is in
 * flight, and it is what a report template is really talking to when it calls `report.build()` —
 * the {@see Run} element forwards to it, which is how templates written for Lab Reports keep
 * working while the machinery underneath is entirely different.
 *
 * ## Nothing is held in memory
 *
 * Rows go straight to the writer, and the writer goes straight to a file handle. The advanced and
 * query types walk their element query in batches and free each batch before fetching the next.
 * That is the fix for Lab Reports issue #6 ("Allowed memory size of 268435456 bytes exhausted"
 * with a 512 MB limit): the old plugin's advanced reports also batched, but everything else about
 * a long run was holding memory anyway.
 *
 * ## The three things that actually leak on a long build
 *
 * Worth naming, because they are not the report's own data and none of them is obvious:
 *
 * 1. **Yii's logger accumulates every message in memory** and only flushes at its own interval.
 *    Over a two-hour export with query logging on, that is the largest single allocation in the
 *    process and it has nothing to do with the report.
 * 2. **Query logging and profiling copy every SQL statement**, twice, into that same buffer.
 *    Switched off for the duration unless the plugin is in debug mode, where the trade is worth
 *    it because somebody is watching.
 * 3. **PHP's cycle collector does not run while a loop is allocating steadily.** Elements hold
 *    references to their field values which hold references back; a periodic explicit collection
 *    is the difference between flat memory and a slow climb.
 */
class Session
{
    private Report $report;
    private Run $run;
    private WriterInterface $writer;

    /** @var array<string, mixed> Normalised parameter answers, as templates see them. */
    private array $paramValues;

    /** @var callable|null fn(float $progress, string $label): void */
    private $progress;

    private array $headings = [];
    private bool $opened = false;

    /**
     * Whether the writer was *ever* opened.
     *
     * Separate from `$opened`, which `finish()` clears. The runner asks this question after
     * closing the file, to tell "the template wrote nothing" from "the template wrote rows" —
     * reading the live flag there reports every successful report as an empty one.
     */
    private bool $everOpened = false;
    private int $rowsWritten = 0;
    private bool $reachedLimit = false;

    /** A preview stops here. Null means "as many as there are". */
    private ?int $maxRows = null;

    /** Preview mode keeps the rows as well as writing them, so the screen has something to show. */
    private bool $collect = false;
    private array $collected = [];

    private int $batchSize = 100;

    private bool $restoreLogging = false;
    private bool $restoreProfiling = false;

    public function __construct(
        Report $report,
        Run $run,
        WriterInterface $writer,
        array $paramValues = [],
        ?callable $progress = null,
    ) {
        $this->report = $report;
        $this->run = $run;
        $this->writer = $writer;
        $this->paramValues = $paramValues;
        $this->progress = $progress;
        $this->batchSize = $report->getEffectiveBatchSize();
    }

    // Configuration
    // -------------------------------------------------------------------------

    public function setMaxRows(?int $maxRows): static
    {
        $this->maxRows = $maxRows !== null ? max(1, $maxRows) : null;

        return $this;
    }

    public function setCollect(bool $collect): static
    {
        $this->collect = $collect;

        return $this;
    }

    public function getParamValues(): array
    {
        return $this->paramValues;
    }

    public function getRowsWritten(): int
    {
        return $this->rowsWritten;
    }

    public function getHeadings(): array
    {
        return $this->headings;
    }

    public function getCollected(): array
    {
        return $this->collected;
    }

    public function getReachedLimit(): bool
    {
        return $this->reachedLimit;
    }

    public function getWasOpened(): bool
    {
        return $this->everOpened;
    }

    // Lifecycle
    // -------------------------------------------------------------------------

    public function begin(): void
    {
        $db = Craft::$app->getDb();

        if (!Plugin::getInstance()->getSettings()->debug) {
            $this->restoreLogging = $db->enableLogging;
            $this->restoreProfiling = $db->enableProfiling;
            $db->enableLogging = false;
            $db->enableProfiling = false;
        }
    }

    /** Close the file and return its size in bytes. Safe to call twice. */
    public function finish(): int
    {
        $size = 0;

        if ($this->opened) {
            $this->opened = false;

            try {
                $size = $this->writer->close();
            } catch (\Throwable $e) {
                // `finish()` is called from the runner's *catch* block as well as its happy path.
                // A writer that throws while closing a half-written file would replace the real
                // failure with its own, and the real one is the useful one.
                Craft::warning('Reportr could not close the report file: ' . $e->getMessage(), 'reportr');
            }
        }

        $db = Craft::$app->getDb();
        $db->enableLogging = $this->restoreLogging || $db->enableLogging;
        $db->enableProfiling = $this->restoreProfiling || $db->enableProfiling;

        return $size;
    }

    // Rows
    // -------------------------------------------------------------------------

    public function setHeadings(array $headings): static
    {
        if ($this->opened) {
            throw new RuntimeException('Column headings have to be set before the first row is written.');
        }

        $this->headings = array_values($headings);

        return $this;
    }

    public function addRow(array $row): bool
    {
        if ($this->reachedLimit) {
            return false;
        }

        $this->open();

        if ($this->maxRows !== null && $this->rowsWritten >= $this->maxRows) {
            $this->reachedLimit = true;

            return false;
        }

        $written = $this->writer->writeRow($row);

        if ($written) {
            $this->rowsWritten++;
            $this->run->totalRows = $this->rowsWritten;

            if ($this->collect) {
                $this->collected[] = array_values($row);
            }
        }

        return $written;
    }

    public function addRows(array $rows): int
    {
        $written = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $written += $this->addRow($row) ? 1 : 0;

            if ($this->reachedLimit) {
                break;
            }
        }

        return $written;
    }

    private function open(): void
    {
        if (!$this->opened) {
            $this->writer->open($this->headings);
            $this->opened = true;
            $this->everOpened = true;
        }
    }

    // Building
    // -------------------------------------------------------------------------

    /**
     * The method a report template calls.
     *
     * Dispatches on the *report's* type rather than on the shape of the arguments, so that a
     * template configured as advanced but written as basic fails with a sentence about the
     * mismatch instead of a `TypeError` from four frames down.
     */
    public function build(mixed $param1, mixed $param2 = null): int
    {
        if ($this->report->type === Report::TYPE_ADVANCED) {
            if (!is_array($param1)) {
                throw new RuntimeException('An advanced report’s build() takes an array of column headings first.');
            }

            if (!$param2 instanceof Query) {
                throw new RuntimeException('An advanced report’s build() takes an unexecuted query as its second argument. Remove the .all() from the end of it.');
            }

            return $this->buildAdvanced($param1, $param2);
        }

        if (!is_array($param1)) {
            throw new RuntimeException('A basic report’s build() takes a single array of rows.');
        }

        return $this->buildBasic($param1);
    }

    /**
     * A basic report: every row is already in the array it was handed.
     *
     * The first row is taken as the column headings, which is how every Lab Reports template is
     * written and therefore how it has to keep working. It is *not* written again as a data row —
     * the writer emits it as a header, so CSV output is byte-identical while JSON and XML get
     * real keys instead of `column1`.
     */
    private function buildBasic(array $rows): int
    {
        $rows = array_values($rows);
        $total = count($rows);

        if ($total === 0) {
            $this->open();

            return 0;
        }

        if ($this->headings === [] && is_array($rows[0])) {
            $this->headings = array_map(static fn($value) => (string)$value, array_values($rows[0]));
            array_shift($rows);
            $total--;
        }

        $this->open();

        $index = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->addRow($row);

            if ($this->reachedLimit) {
                break;
            }

            if (++$index % 500 === 0) {
                $this->reportProgress($index / max(1, $total), "{$index} / {$total}");
            }
        }

        $this->reportProgress(1, Craft::t('reportr', '{count} rows', ['count' => $this->rowsWritten]));

        return $this->rowsWritten;
    }

    /**
     * An advanced report: headings plus an unexecuted query, walked in batches.
     *
     * Paged with `limit`/`offset` on a *clone* per batch. The clone matters: an element query
     * caches its own results, so reusing one instance would hold every batch fetched so far and
     * defeat the entire point of batching.
     */
    private function buildAdvanced(array $headings, Query $query): int
    {
        $formatFunction = Plugin::getInstance()->reports->formatFunction((string)$this->report->formatFunction);

        if ($formatFunction === null) {
            throw new RuntimeException(sprintf(
                'The formatting function “%s” is not defined in config/reportr.php.',
                (string)$this->report->formatFunction,
            ));
        }

        if ($this->headings === []) {
            $this->headings = array_map(static fn($value) => (string)$value, array_values($headings));
        }

        $this->open();

        return $this->walk($query, function(mixed $element) use ($formatFunction): void {
            $row = $formatFunction($element);

            if (!is_array($row)) {
                throw new RuntimeException(sprintf(
                    'The formatting function “%s” must return an array. It returned %s.',
                    (string)$this->report->formatFunction,
                    get_debug_type($row),
                ));
            }

            $this->addRow($row);
        });
    }

    /**
     * A query report: no template at all, columns resolved from the spec.
     */
    public function buildFromQuerySpec(): int
    {
        $spec = $this->report->getQuerySpec();
        $builder = Plugin::getInstance()->queries;
        $query = $builder->build($spec, $this->paramValues);

        if ($this->headings === []) {
            $this->headings = $spec->headings();
        }

        $this->open();

        return $this->walk($query, function(ElementInterface $element) use ($builder, $spec): void {
            $this->addRow($builder->row($element, $spec));
        });
    }

    /**
     * Walk a query in batches, applying a callback to each result.
     *
     * @param callable(mixed): void $each
     */
    private function walk(Query $query, callable $each): int
    {
        // A query that already carries a limit means it — a report asked for the top 100 must not
        // silently become a report of everything because the batcher overwrote the limit.
        $requestedLimit = $query->limit !== null ? (int)$query->limit : null;
        $baseOffset = $query->offset !== null ? (int)$query->offset : 0;

        $total = $this->countOf($query);

        if ($requestedLimit !== null) {
            $total = min($total, $requestedLimit);
        }

        if ($this->maxRows !== null) {
            $total = min($total, $this->maxRows);
        }

        $processed = 0;
        $offset = 0;

        while (true) {
            $size = $this->batchSize;

            if ($requestedLimit !== null) {
                $size = min($size, $requestedLimit - $processed);
            }

            if ($this->maxRows !== null) {
                $size = min($size, $this->maxRows - $this->rowsWritten);
            }

            if ($size <= 0) {
                break;
            }

            $batchQuery = clone $query;
            $results = $batchQuery->limit($size)->offset($baseOffset + $offset)->all();
            $count = count($results);

            if ($count === 0) {
                break;
            }

            foreach ($results as $result) {
                $each($result);
                $processed++;

                if ($this->reachedLimit) {
                    break 2;
                }
            }

            $offset += $count;

            $this->reportProgress($total > 0 ? min(1, $processed / $total) : 1, "{$processed} / {$total}");
            $this->collectGarbage($results, $batchQuery);

            if ($count < $size) {
                break;
            }
        }

        $this->reportProgress(1, Craft::t('reportr', '{count} rows', ['count' => $this->rowsWritten]));

        return $this->rowsWritten;
    }

    /**
     * How many results there are, without letting the counting query inherit the paging.
     *
     * `count()` on a query that has already had `limit()` applied answers "how many in this page",
     * which makes every progress bar report 100% from the first batch onwards.
     */
    private function countOf(Query $query): int
    {
        $counter = clone $query;
        $counter->limit = null;
        $counter->offset = null;

        try {
            // MySQL's COUNT() comes back as a string on some drivers, and `(int)` on a string of
            // digits is the one cast that is always right here.
            return (int)$counter->count();
        } catch (\Throwable $e) {
            Craft::warning('Could not count the report query: ' . $e->getMessage(), 'reportr');

            return 0;
        }
    }

    /**
     * Free what the last batch allocated.
     *
     * The logger flush is the important line and the least obvious: Yii buffers log messages in
     * memory and only writes them out at intervals, so a long build accumulates every query it
     * ever ran even though nothing is reading them.
     */
    private function collectGarbage(array &$results, Query $query): void
    {
        unset($results, $query);

        Craft::getLogger()->flush();
        gc_collect_cycles();

        $this->run->peakMemory = memory_get_peak_usage(true);
    }

    private function reportProgress(float $progress, string $label): void
    {
        if ($this->progress !== null) {
            ($this->progress)(max(0, min(1, $progress)), $label);
        }
    }
}
