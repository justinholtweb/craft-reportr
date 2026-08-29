<?php

declare(strict_types=1);

namespace justinholtweb\reportr\writers;

use craft\helpers\FileHelper;
use justinholtweb\reportr\models\FormatOptions;
use RuntimeException;
use ZipArchive;

/**
 * A real .xlsx file, with no dependencies.
 *
 * "Other export formats" is the second item on Lab Reports' own roadmap and the one people
 * actually ask for, because the person who requested the report opens it in Excel. A CSV is a
 * fine interchange format and a poor deliverable: it loses the header row's emphasis, it argues
 * about encodings, and Excel reformats anything that looks like a date.
 *
 * The file is OOXML — a zip of XML parts — written here by hand rather than by pulling in
 * PhpSpreadsheet, for two reasons. PhpSpreadsheet builds the whole workbook in memory, which is
 * the failure mode this plugin exists to avoid; and a reporting plugin whose install drags in a
 * hundred transitive packages is a plugin that eventually blocks a Craft update.
 *
 * Cells use **inline strings** (`t="inlineStr"`) rather than the shared-string table. The shared
 * table is smaller for repetitive data and requires holding every distinct string in memory until
 * the file is closed, so it trades exactly the wrong way for an export of a million rows.
 */
class XlsxWriter extends BaseWriter
{
    /** Excel's hard limit. Row 1,048,577 does not exist and a file containing one will not open. */
    private const MAX_ROWS = 1048576;

    private string $sheetPath;
    private int $rowIndex = 0;
    private bool $truncated = false;

    public function __construct(string $path, FormatOptions $options)
    {
        parent::__construct($path, $options);

        $this->sheetPath = $path . '.sheet1.xml';
    }

    public static function extension(): string
    {
        return 'xlsx';
    }

    public static function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public static function isSupported(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function open(array $headings = []): void
    {
        if (!self::isSupported()) {
            throw new RuntimeException('The XLSX format needs PHP’s zip extension, which is not installed.');
        }

        $this->headings = array_map(static fn($heading) => (string)$heading, array_values($headings));

        // The sheet is written to its own file first and zipped at the end. Building it as a
        // string would put the entire report in memory, which is the thing this format is most
        // likely to be asked to avoid.
        $handle = @fopen($this->sheetPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open “{$this->sheetPath}” for writing.");
        }

        $this->handle = $handle;

        $this->write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n");
        $this->write('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
        $this->write('<sheetData>');

        if ($this->options->headers && $this->headings !== []) {
            $this->putRow($this->headings, true);
        }
    }

    public function writeRow(array $row): bool
    {
        if ($this->rowIndex >= self::MAX_ROWS) {
            $this->truncated = true;

            return false;
        }

        $written = $this->putRow($this->stringifyRow($row), false);

        if ($written) {
            $this->rowsWritten++;
        }

        return $written;
    }

    public function getWasTruncated(): bool
    {
        return $this->truncated;
    }

    private function putRow(array $values, bool $bold): bool
    {
        $this->rowIndex++;
        $xml = '<row r="' . $this->rowIndex . '">';

        foreach (array_values($values) as $index => $value) {
            $reference = self::columnName($index) . $this->rowIndex;
            $style = $bold ? ' s="1"' : '';

            if ($value === '') {
                $xml .= '<c r="' . $reference . '"' . $style . '/>';

                continue;
            }

            // Numbers go in as numbers so that a spreadsheet can total the column. Leading zeros
            // are the exception: a postcode, an account number or a phone number written as a
            // number loses them, and nobody thanks you for "0117" becoming 117.
            if ($this->isNumeric($value)) {
                $xml .= '<c r="' . $reference . '"' . $style . '><v>' . $value . '</v></c>';

                continue;
            }

            $xml .= '<c r="' . $reference . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
                . $this->escape($value) . '</t></is></c>';
        }

        return $this->write($xml . '</row>');
    }

    private function isNumeric(string $value): bool
    {
        if (!is_numeric($value)) {
            return false;
        }

        if (strlen($value) > 1 && $value[0] === '0' && $value[1] !== '.') {
            return false;
        }

        // Beyond 15 significant digits a spreadsheet silently rounds, which turns a credit-card
        // or IMEI number into a different number that still looks plausible.
        return strlen(ltrim($value, '-+0.')) <= 15;
    }

    private function escape(string $value): string
    {
        $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{10FFFF}]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function close(): int
    {
        if ($this->handle !== null) {
            $this->write('</sheetData></worksheet>');
            fclose($this->handle);
            $this->handle = null;
        }

        $this->buildArchive();

        FileHelper::unlink($this->sheetPath);
        clearstatcache(true, $this->path);

        return (int)@filesize($this->path);
    }

    private function buildArchive(): void
    {
        $zip = new ZipArchive();

        if ($zip->open($this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create the workbook at “{$this->path}”.");
        }

        $sheetName = $this->escape(FormatOptions::sanitizeSheetName($this->options->sheetName));

        $zip->addFromString('[Content_Types].xml', self::CONTENT_TYPES);
        $zip->addFromString('_rels/.rels', self::ROOT_RELS);
        $zip->addFromString('xl/workbook.xml', sprintf(self::WORKBOOK, $sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::WORKBOOK_RELS);
        $zip->addFromString('xl/styles.xml', self::STYLES);
        $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');

        if (!$zip->close()) {
            throw new RuntimeException("Unable to finish the workbook at “{$this->path}”.");
        }
    }

    /** 0 => A, 25 => Z, 26 => AA. */
    public static function columnName(int $index): string
    {
        $name = '';

        for ($remaining = $index + 1; $remaining > 0; $remaining = intdiv($remaining - 1, 26)) {
            $name = chr(65 + (($remaining - 1) % 26)) . $name;
        }

        return $name;
    }

    private const CONTENT_TYPES = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    </Types>
    XML;

    private const ROOT_RELS = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
    </Relationships>
    XML;

    private const WORKBOOK = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets><sheet name="%s" sheetId="1" r:id="rId1"/></sheets>
    </workbook>
    XML;

    private const WORKBOOK_RELS = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
    </Relationships>
    XML;

    private const STYLES = <<<'XML'
    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>
    <fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
    <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>
    <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
    </styleSheet>
    XML;
}
