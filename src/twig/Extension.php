<?php

declare(strict_types=1);

namespace justinholtweb\reportr\twig;

use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use DateTimeInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Three filters, and all three exist because of one closed issue.
 *
 * They are prefixed rather than called `cell`, `column` and `values`, because Twig lets a later
 * extension silently replace a filter of the same name — so a generic name is a way to break
 * another plugin's templates, or to have yours broken, with no error anywhere.
 *
 * Lab Reports issue #3 is somebody trying to put a Table field's rows into a report column and
 * being told, in effect, to get better at Twig. It is a fair thing to want and it is fiddly in
 * every direction — a relation field is a query, a table field is a list of hashes, a checkboxes
 * field is a list of objects with `value` on them, and each needs different Twig to flatten.
 *
 * `|reportCell` handles all of them: give it anything a Craft field can hold and it gives back one
 * string. `|reportColumn` pulls one key out of a list of rows. `|reportValues` gets the raw values out of
 * a multi-select. Between them, the awkward columns in a report template stop being a puzzle.
 */
class Extension extends AbstractExtension
{
    public function getName(): string
    {
        return 'reportr';
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('reportCell', [$this, 'cell']),
            new TwigFilter('reportColumn', [$this, 'column']),
            new TwigFilter('reportValues', [$this, 'values']),
        ];
    }

    /**
     * Anything a field can hold, as one string.
     *
     * The `is_array()` test is narrow on purpose. Every Craft element is `Traversable` — Yii's
     * `Model` implements `IteratorAggregate` — so a "flatten anything iterable" helper silently
     * explodes an entry into its own attribute values, and the cell comes out as a dump of the
     * element's metadata rather than its title. An element is a value here, not a container.
     */
    public function cell(mixed $value, string $separator = ', ', string $dateFormat = 'Y-m-d H:i'): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value === true) {
            return '1';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format($dateFormat);
        }

        if ($value instanceof ElementQuery) {
            $value = $value->all();
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            return implode($separator, array_map(
                fn($item) => $this->cell($item, $separator, $dateFormat),
                $value,
            ));
        }

        if ($value instanceof ElementInterface || (is_object($value) && method_exists($value, '__toString'))) {
            return (string)$value;
        }

        // A dropdown or checkbox option is an object with `value` and `label` on it, and the
        // value is what a report means by the answer.
        if (is_object($value) && isset($value->value)) {
            return (string)$value->value;
        }

        return '';
    }

    /**
     * One key out of a list of rows.
     *
     * `entry.priceTable|reportColumn('amount')|reportCell` — the Table field case from the issue, in one
     * line. Works on related elements too: `entry.authors|reportColumn('email')`.
     */
    public function column(mixed $rows, string $key): array
    {
        if ($rows instanceof ElementQuery) {
            $rows = $rows->all();
        }

        if ($rows instanceof \Illuminate\Support\Collection) {
            $rows = $rows->all();
        }

        if (!is_array($rows)) {
            return [];
        }

        $values = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $values[] = $row[$key] ?? null;

                continue;
            }

            if (is_object($row)) {
                $getter = 'get' . ucfirst($key);
                $values[] = method_exists($row, $getter) ? $row->$getter() : ($row->$key ?? null);
            }
        }

        return array_values(array_filter($values, static fn($value) => $value !== null && $value !== ''));
    }

    /** The raw values of a multi-select field, without its labels. */
    public function values(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'getArrayCopy')) {
            $value = $value->getArrayCopy();
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        $values = [];

        foreach ($value as $item) {
            if (is_object($item) && isset($item->value)) {
                $values[] = $item->value;
            } elseif (is_scalar($item)) {
                $values[] = $item;
            }
        }

        return $values;
    }
}
