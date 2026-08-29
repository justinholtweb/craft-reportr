<?php

declare(strict_types=1);

namespace justinholtweb\reportr\writers;

/**
 * A JSON array, streamed.
 *
 * Written bracket-by-bracket rather than with one `json_encode()` at the end, because encoding at
 * the end means holding every row in memory — the exact failure Lab Reports issue #6 describes.
 * Each *row* is encoded individually, so the escaping is still PHP's and not hand-rolled.
 */
class JsonWriter extends BaseWriter
{
    private bool $wroteFirstRow = false;

    public static function extension(): string
    {
        return 'json';
    }

    public static function mimeType(): string
    {
        return 'application/json';
    }

    public function open(array $headings = []): void
    {
        parent::open($headings);

        $this->write('[');
    }

    public function writeRow(array $row): bool
    {
        $values = $this->stringifyRow($row);

        $payload = $this->options->keyed
            ? array_combine($this->keysFor($values), $values)
            : $values;

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($this->options->pretty ? JSON_PRETTY_PRINT : 0),
        );

        if ($encoded === false) {
            return false;
        }

        $separator = $this->wroteFirstRow ? ',' : '';

        if ($this->options->pretty) {
            $encoded = "\n" . preg_replace('/^/m', '    ', $encoded);
        }

        $written = $this->write($separator . $encoded);
        $this->wroteFirstRow = true;

        if ($written) {
            $this->rowsWritten++;
        }

        return $written;
    }

    public function close(): int
    {
        if ($this->handle !== null) {
            $this->write($this->options->pretty && $this->wroteFirstRow ? "\n]\n" : "]\n");
        }

        return parent::close();
    }
}
