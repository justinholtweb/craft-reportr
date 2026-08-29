<?php

declare(strict_types=1);

namespace justinholtweb\reportr\build;

/**
 * What a preview run produced: some rows, or the reason there are none.
 *
 * Previews exist because the alternative is the loop Lab Reports leaves you in — save the
 * template, run the report, queue a job, wait, download a file, discover the third column is
 * blank, repeat. A preview runs the same code path with a row cap and writes nothing anywhere.
 */
class PreviewResult
{
    /** @param string[] $headings @param array<int, array> $rows */
    public function __construct(
        public readonly array $headings = [],
        public readonly array $rows = [],
        public readonly int $totalRows = 0,
        public readonly bool $truncated = false,
        public readonly ?string $error = null,
        public readonly int $durationMs = 0,
    ) {
    }

    public static function failure(string $error): self
    {
        return new self(error: $error);
    }

    public function getSucceeded(): bool
    {
        return $this->error === null;
    }
}
