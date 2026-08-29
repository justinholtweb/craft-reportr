<?php

declare(strict_types=1);

namespace justinholtweb\reportr\writers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use DateTimeInterface;
use justinholtweb\reportr\models\FormatOptions;
use RuntimeException;

abstract class BaseWriter implements WriterInterface
{
    protected string $path;
    protected FormatOptions $options;

    /** @var resource|null */
    protected $handle = null;

    /** @var string[] */
    protected array $headings = [];

    protected int $rowsWritten = 0;

    public function __construct(string $path, FormatOptions $options)
    {
        $this->path = $path;
        $this->options = $options;
    }

    public static function mimeType(): string
    {
        return 'application/octet-stream';
    }

    public function open(array $headings = []): void
    {
        $this->headings = array_map(static fn($heading) => (string)$heading, array_values($headings));

        $handle = @fopen($this->path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open “{$this->path}” for writing.");
        }

        $this->handle = $handle;
    }

    public function close(): int
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }

        clearstatcache(true, $this->path);

        return (int)@filesize($this->path);
    }

    /** @return resource */
    protected function requireHandle()
    {
        if ($this->handle === null) {
            throw new RuntimeException('The report file is not open. Call open() first.');
        }

        return $this->handle;
    }

    protected function write(string $bytes): bool
    {
        return fwrite($this->requireHandle(), $bytes) !== false;
    }

    /**
     * Flatten one cell to a string.
     *
     * The `is_array()` test is deliberately narrow. Every Craft element is `Traversable` — Yii's
     * `Model` implements `IteratorAggregate` — so a "flatten anything iterable" helper silently
     * explodes an entry into its *attribute values* and the column comes out as a comma-separated
     * dump of its own metadata. Only real arrays, element queries and collections are containers
     * here; an element is a value with a `__toString()`.
     */
    protected function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof ElementQuery) {
            $value = $value->all();
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            return implode($this->options->listSeparator, array_map(
                fn($item) => $this->stringify($item),
                $value,
            ));
        }

        if ($value instanceof ElementInterface) {
            return (string)$value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        if (is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded !== false ? $encoded : '';
        }

        return '';
    }

    /** @return string[] */
    protected function stringifyRow(array $row): array
    {
        return array_map(fn($value) => $this->stringify($value), array_values($row));
    }

    /**
     * Headings for a row-keyed format, padded or trimmed to match the row.
     *
     * A template is free to write rows wider than its own header — `report.addRow()` takes
     * whatever it is given — and a JSON writer that zips two different-length arrays produces
     * either a fatal or silently truncated rows depending on which way round they are.
     */
    protected function keysFor(array $row): array
    {
        $keys = $this->headings;
        $count = count($row);

        for ($index = count($keys); $index < $count; $index++) {
            $keys[] = 'column' . ($index + 1);
        }

        return array_slice($keys, 0, $count);
    }

    protected function logDebug(string $message): void
    {
        Craft::info($message, 'reportr');
    }
}
