<?php

declare(strict_types=1);

namespace justinholtweb\reportr\models;

use craft\base\Model;

/**
 * The knobs a particular export format needs.
 *
 * One model rather than a column per option, because the set is format-specific and a schema with
 * `xmlRootElement` in it is a schema that will need altering the next time a format is added.
 *
 * `bom` defaults to **on**, which is the opposite of the usual instinct and is right for the same
 * reason spreadsheets exist: Excel on Windows reads a UTF-8 CSV without a byte-order mark as
 * Windows-1252, so every accented name in the export arrives mangled. The three bytes are ugly
 * and they are the difference between a file that opens correctly and a support ticket.
 */
class FormatOptions extends Model
{
    public string $delimiter = ',';
    public string $enclosure = '"';
    public bool $bom = true;
    public bool $headers = true;

    /** XLSX sheet name. Excel refuses more than 31 characters and the characters `[]:*?/\`. */
    public string $sheetName = 'Report';

    public string $xmlRoot = 'rows';
    public string $xmlRow = 'row';
    public bool $pretty = false;

    /** JSON: emit objects keyed by the headings rather than positional arrays. */
    public bool $keyed = true;

    /** How a multi-valued cell is joined when a format has nowhere to put a list. */
    public string $listSeparator = ', ';

    /**
     * Neutralise spreadsheet formulas in delimited exports.
     *
     * A cell beginning `=`, `+`, `-` or `@` is executed as a formula when the file is opened, so
     * an export of anything a member of the public typed — a contact form, a review, a username —
     * is a way to run code on the machine of whoever opens the report. Prefixing with an
     * apostrophe is the standard mitigation; spreadsheets hide it and treat the cell as text.
     * Negative numbers are exempt, or every debit column would come out quoted.
     */
    public bool $escapeFormulas = true;

    public static function fromArray(?array $config): self
    {
        $options = new self();

        if ($config === null) {
            return $options;
        }

        $delimiter = (string)($config['delimiter'] ?? ',');

        // A single byte, always: `fputcsv()` throws a ValueError on anything else in PHP 8.2+,
        // and the readable spellings are what a person types into the field.
        $options->delimiter = match (mb_strtolower(trim($delimiter))) {
            '', 'comma' => ',',
            'tab', '\t', 'tab-separated' => "\t",
            'semicolon' => ';',
            'pipe' => '|',
            default => mb_substr($delimiter, 0, 1),
        };

        $enclosure = (string)($config['enclosure'] ?? '"');
        $options->enclosure = $enclosure !== '' ? mb_substr($enclosure, 0, 1) : '"';

        $options->bom = !isset($config['bom']) || (bool)$config['bom'];
        $options->headers = !isset($config['headers']) || (bool)$config['headers'];
        $options->sheetName = self::sanitizeSheetName((string)($config['sheetName'] ?? 'Report'));
        $options->xmlRoot = self::sanitizeXmlName((string)($config['xmlRoot'] ?? 'rows'), 'rows');
        $options->xmlRow = self::sanitizeXmlName((string)($config['xmlRow'] ?? 'row'), 'row');
        $options->pretty = !empty($config['pretty']);
        $options->keyed = !isset($config['keyed']) || (bool)$config['keyed'];
        $options->listSeparator = (string)($config['listSeparator'] ?? ', ');
        $options->escapeFormulas = !isset($config['escapeFormulas']) || (bool)$config['escapeFormulas'];

        return $options;
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'delimiter' => $this->delimiter,
            'enclosure' => $this->enclosure,
            'bom' => $this->bom,
            'headers' => $this->headers,
            'sheetName' => $this->sheetName,
            'xmlRoot' => $this->xmlRoot,
            'xmlRow' => $this->xmlRow,
            'pretty' => $this->pretty,
            'keyed' => $this->keyed,
            'listSeparator' => $this->listSeparator,
            'escapeFormulas' => $this->escapeFormulas,
        ];
    }

    public static function sanitizeSheetName(string $name): string
    {
        $name = str_replace(['[', ']', ':', '*', '?', '/', '\\'], '', trim($name));
        $name = mb_substr($name, 0, 31);

        return $name !== '' ? $name : 'Report';
    }

    /**
     * An XML element name that a parser will accept.
     *
     * A heading of "Total (£)" makes a splendid CSV column and an XML document that no parser
     * will open, so the same string cannot be used for both.
     */
    public static function sanitizeXmlName(string $name, string $fallback = 'value'): string
    {
        $name = preg_replace('/[^A-Za-z0-9_.\-]/', '', trim($name)) ?? '';
        $name = ltrim($name, '-.0123456789');

        if ($name === '' || stripos($name, 'xml') === 0) {
            return $fallback;
        }

        return $name;
    }
}
