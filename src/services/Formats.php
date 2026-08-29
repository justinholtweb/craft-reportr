<?php

declare(strict_types=1);

namespace justinholtweb\reportr\services;

use Craft;
use craft\base\Component;
use justinholtweb\reportr\models\FormatOptions;
use justinholtweb\reportr\writers\DelimitedWriter;
use justinholtweb\reportr\writers\HtmlWriter;
use justinholtweb\reportr\writers\JsonWriter;
use justinholtweb\reportr\writers\NdjsonWriter;
use justinholtweb\reportr\writers\TsvWriter;
use justinholtweb\reportr\writers\WriterInterface;
use justinholtweb\reportr\writers\XlsxWriter;
use justinholtweb\reportr\writers\XmlWriter;

/**
 * The export formats, and which writer produces each.
 *
 * A registry rather than a `match` in the runner, so that a site can add its own format from
 * `config/reportr.php` without touching the plugin — the one extension point a reporting tool
 * genuinely needs, since the awkward format is always the one the finance system wants.
 */
class Formats extends Component
{
    public const CSV = 'csv';
    public const TSV = 'tsv';
    public const JSON = 'json';
    public const NDJSON = 'ndjson';
    public const XML = 'xml';
    public const XLSX = 'xlsx';
    public const HTML = 'html';

    /** @var array<string, class-string<WriterInterface>>|null */
    private ?array $writers = null;

    /** @return array<string, class-string<WriterInterface>> */
    public function all(): array
    {
        if ($this->writers !== null) {
            return $this->writers;
        }

        $writers = [
            self::CSV => DelimitedWriter::class,
            self::TSV => TsvWriter::class,
            self::JSON => JsonWriter::class,
            self::NDJSON => NdjsonWriter::class,
            self::XML => XmlWriter::class,
            self::HTML => HtmlWriter::class,
            self::XLSX => XlsxWriter::class,
        ];

        foreach (\justinholtweb\reportr\Plugin::getInstance()->getConfigItem('writers') ?? [] as $key => $class) {
            if (is_string($class) && class_exists($class) && is_subclass_of($class, WriterInterface::class)) {
                $writers[(string)$key] = $class;
            }
        }

        return $this->writers = $writers;
    }

    public function has(string $format): bool
    {
        return isset($this->all()[$format]);
    }

    /** @return class-string<WriterInterface>|null */
    public function writerClass(string $format): ?string
    {
        return $this->all()[$format] ?? null;
    }

    public function create(string $format, string $path, FormatOptions $options): WriterInterface
    {
        $class = $this->writerClass($format) ?? DelimitedWriter::class;

        return new $class($path, $options);
    }

    public function extension(string $format): string
    {
        $class = $this->writerClass($format);

        return $class !== null ? $class::extension() : 'csv';
    }

    public function mimeType(string $format): string
    {
        $class = $this->writerClass($format);

        return $class !== null ? $class::mimeType() : 'application/octet-stream';
    }

    /**
     * Whether this install can actually produce the format.
     *
     * XLSX needs `ext-zip`, which a lot of shared hosting leaves out. Better to grey the option
     * out on the form than to let somebody schedule a nightly report that fails every night.
     */
    public function isSupported(string $format): bool
    {
        $class = $this->writerClass($format);

        if ($class === null) {
            return false;
        }

        return !method_exists($class, 'isSupported') || $class::isSupported();
    }

    /** @return array<string, string> Format key => label, for a select. */
    public function options(bool $supportedOnly = false): array
    {
        $labels = [
            self::CSV => Craft::t('reportr', 'CSV'),
            self::TSV => Craft::t('reportr', 'TSV (tab separated)'),
            self::XLSX => Craft::t('reportr', 'Excel workbook (XLSX)'),
            self::JSON => Craft::t('reportr', 'JSON'),
            self::NDJSON => Craft::t('reportr', 'NDJSON (one object per line)'),
            self::XML => Craft::t('reportr', 'XML'),
            self::HTML => Craft::t('reportr', 'HTML table'),
        ];

        $options = [];

        foreach ($this->all() as $key => $class) {
            $label = $labels[$key] ?? strtoupper($key);

            if (!$this->isSupported($key)) {
                if ($supportedOnly) {
                    continue;
                }

                $label .= ' — ' . Craft::t('reportr', 'not available on this server');
            }

            $options[$key] = $label;
        }

        return $options;
    }
}
