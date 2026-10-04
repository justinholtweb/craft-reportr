<?php

declare(strict_types=1);

namespace justinholtweb\reportr\writers;

/**
 * Newline-delimited JSON — one object per line.
 *
 * The format to reach for when the report is an *import* for something else: a consumer can read
 * it a line at a time without a streaming JSON parser, and a truncated file still yields every
 * complete row before the truncation.
 */
class NdjsonWriter extends BaseWriter
{
    public static function extension(): string
    {
        return 'ndjson';
    }

    public static function mimeType(): string
    {
        return 'application/x-ndjson';
    }

    public function writeRow(array $row): bool
    {
        $values = $this->stringifyRow($row);

        $payload = $this->options->keyed
            ? array_combine($this->keysFor($values), $values)
            : $values;

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return false;
        }

        $written = $this->write($encoded . "\n");

        if ($written) {
            $this->rowsWritten++;
        }

        return $written;
    }
}
