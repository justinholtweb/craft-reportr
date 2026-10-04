<?php

declare(strict_types=1);

namespace justinholtweb\reportr\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;

/**
 * One column of a point-and-click report.
 *
 * The key is a tiny expression language, and it is small on purpose — three prefixes, because a
 * fourth would mean somebody has to learn it:
 *
 * - `attr:title`, `attr:dateCreated`, `attr:author.email` — anything the element itself exposes,
 *   dotted to walk into a related element.
 * - `field:bookAuthor`, `field:specSheet.0.price` — a custom field, dotted the same way, which is
 *   how a Table field's column gets out. This is Lab Reports issue #3, which was closed by
 *   telling the reporter to write more Twig.
 * - `twig:{{ object.title|upper }} ({{ object.id }})` — an object template, for the row nobody
 *   anticipated. Evaluated by Craft's `renderObjectTemplate()`, which is **not** a sandbox: it can
 *   reach `craft.app` and anything else Twig can. So only an admin may add or change one (see
 *   helpers\Access and Report::validateAccess()); everyone else can keep or remove an admin's.
 */
class Column extends Model
{
    public const PREFIX_ATTR = 'attr';
    public const PREFIX_FIELD = 'field';
    public const PREFIX_TWIG = 'twig';

    public const FORMAT_AUTO = 'auto';
    public const FORMAT_TEXT = 'text';
    public const FORMAT_NUMBER = 'number';
    public const FORMAT_DATE = 'date';
    public const FORMAT_DATETIME = 'datetime';
    public const FORMAT_BOOLEAN = 'boolean';
    public const FORMAT_LIST = 'list';

    public string $key = '';
    public string $heading = '';
    public string $format = self::FORMAT_AUTO;

    /** Separator for list columns, and for any multi-valued field flattened into one cell. */
    public string $separator = ', ';

    /** A PHP date format, for date columns that want something other than the site default. */
    public ?string $dateFormat = null;

    public static function formats(): array
    {
        return [
            self::FORMAT_AUTO => Craft::t('reportr', 'Automatic'),
            self::FORMAT_TEXT => Craft::t('reportr', 'Text'),
            self::FORMAT_NUMBER => Craft::t('reportr', 'Number'),
            self::FORMAT_DATE => Craft::t('reportr', 'Date'),
            self::FORMAT_DATETIME => Craft::t('reportr', 'Date and time'),
            self::FORMAT_BOOLEAN => Craft::t('reportr', 'Yes/no'),
            self::FORMAT_LIST => Craft::t('reportr', 'List'),
        ];
    }

    public static function fromArray(array $config): self
    {
        $column = new self();

        $column->key = trim((string)($config['key'] ?? ''));
        $column->format = (string)($config['format'] ?? self::FORMAT_AUTO);
        $column->separator = (string)($config['separator'] ?? ', ');
        $column->dateFormat = ($config['dateFormat'] ?? null) ?: null;
        $column->heading = trim((string)($config['heading'] ?? '')) ?: $column->defaultHeading();

        if (!isset(self::formats()[$column->format])) {
            $column->format = self::FORMAT_AUTO;
        }

        return $column;
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'key' => $this->key,
            'heading' => $this->heading,
            'format' => $this->format,
            'separator' => $this->separator,
            'dateFormat' => $this->dateFormat,
        ];
    }

    /** @return array{0: string, 1: string} The prefix and everything after it. */
    public function parse(): array
    {
        $position = strpos($this->key, ':');

        if ($position === false) {
            // An unprefixed key is what somebody types first, and "title" meaning `attr:title` is
            // both what they meant and the only reading that could be useful.
            return [self::PREFIX_ATTR, $this->key];
        }

        return [substr($this->key, 0, $position), substr($this->key, $position + 1)];
    }

    private function defaultHeading(): string
    {
        [$prefix, $path] = $this->parse();

        if ($prefix === self::PREFIX_TWIG) {
            return Craft::t('reportr', 'Column');
        }

        $last = $path;
        $dot = strrpos($path, '.');

        if ($dot !== false) {
            $last = substr($path, $dot + 1);
        }

        // Not `StringHelper::toWords()`, which returns an *array* in Craft 5 and hands
        // `toTitleCase()` something it type-errors on.
        $words = preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace(['_', '-'], ' ', $last)) ?? $last;

        return StringHelper::toTitleCase(trim(preg_replace('/\s+/', ' ', $words) ?? $words));
    }
}
