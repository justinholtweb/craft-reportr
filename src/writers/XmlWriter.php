<?php

declare(strict_types=1);

namespace justinholtweb\reportr\writers;

use justinholtweb\reportr\models\FormatOptions;

/**
 * XML, one element per row.
 *
 * The tag names come from the headings, run through {@see FormatOptions::sanitizeXmlName()},
 * because "Total (£)" is a perfectly good column heading and an XML document no parser will
 * open. Where a heading sanitises away to nothing, the column falls back to its position — a
 * document with `<column3>` in it is at least readable, and one that fails to parse is not.
 */
class XmlWriter extends BaseWriter
{
    /** @var string[] */
    private array $tags = [];


    public static function extension(): string
    {
        return 'xml';
    }

    public static function mimeType(): string
    {
        return 'application/xml';
    }

    public function open(array $headings = []): void
    {
        parent::open($headings);

        foreach ($this->headings as $index => $heading) {
            $this->tags[$index] = FormatOptions::sanitizeXmlName($heading, 'column' . ($index + 1));
        }

        $this->write('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $this->write('<' . $this->options->xmlRoot . '>' . "\n");
    }

    public function writeRow(array $row): bool
    {
        $values = $this->stringifyRow($row);

        $xml = '  <' . $this->options->xmlRow . '>' . "\n";

        foreach ($values as $index => $value) {
            $tag = $this->tags[$index] ?? ('column' . ($index + 1));
            $xml .= '    <' . $tag . '>' . $this->escape($value) . '</' . $tag . '>' . "\n";
        }

        $xml .= '  </' . $this->options->xmlRow . '>' . "\n";

        $written = $this->write($xml);

        if ($written) {
            $this->rowsWritten++;
        }

        return $written;
    }

    public function close(): int
    {
        if ($this->handle !== null) {
            $this->write('</' . $this->options->xmlRoot . '>' . "\n");
        }

        return parent::close();
    }

    /**
     * Escape a value for a text node, stripping the control characters XML 1.0 cannot represent.
     *
     * A stray `\x0B` from a pasted spreadsheet cell is invisible in every editor and makes the
     * entire document unparseable — the failure arrives at whoever consumes the export, hours
     * later, as "not well-formed" with no line number that means anything.
     */
    private function escape(string $value): string
    {
        $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{10FFFF}]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
