<?php

declare(strict_types=1);

namespace justinholtweb\reportr\writers;

/**
 * CSV, TSV, and anything else separated by one character.
 *
 * The byte-order mark is written when the options ask for it, which by default they do. It is
 * three bytes of ugliness that decide whether Excel on Windows reads the file as UTF-8 or as
 * Windows-1252 — and a report of customer names that comes out as "JosÃ©" is a report somebody
 * has to redo by hand.
 */
class DelimitedWriter extends BaseWriter
{
    public static function extension(): string
    {
        return 'csv';
    }

    public static function mimeType(): string
    {
        return 'text/csv';
    }

    public function open(array $headings = []): void
    {
        parent::open($headings);

        if ($this->options->bom) {
            $this->write("\xEF\xBB\xBF");
        }

        if ($this->options->headers && $this->headings !== []) {
            $this->putRow($this->headings);
        }
    }

    public function writeRow(array $row): bool
    {
        $written = $this->putRow($this->stringifyRow($row));

        if ($written) {
            $this->rowsWritten++;
        }

        return $written;
    }

    private function putRow(array $row): bool
    {
        if ($this->options->escapeFormulas) {
            $row = array_map(static function(string $value): string {
                if ($value === '' || is_numeric($value)) {
                    return $value;
                }

                return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
            }, $row);
        }

        // `escape: ''` turns off PHP's backslash escaping, which is not part of any CSV dialect
        // and which mangles Windows paths and regular expressions on the way out. It is also
        // deprecated as a default in PHP 8.4, so passing it explicitly is the future-proof form.
        return fputcsv(
            $this->requireHandle(),
            $row,
            $this->options->delimiter,
            $this->options->enclosure,
            '',
        ) !== false;
    }
}
