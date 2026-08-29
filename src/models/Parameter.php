<?php

declare(strict_types=1);

namespace justinholtweb\reportr\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Localization;
use craft\helpers\StringHelper;
use DateTime;

/**
 * One question a report asks before it runs.
 *
 * Lab Reports' issue #1 — "dynamic report parameters", open since 2021 and still the top item on
 * its roadmap — is this class. Without it, "orders in July" and "orders in August" are two
 * report configurations that differ by a hard-coded date, and a year of them is twelve
 * near-identical templates nobody dares delete.
 *
 * A parameter is *declared* on the report and *answered* at run time — in the control panel by a
 * form, on the command line by `--params`, in Twig by `craft.reportr.run()`. Whatever the source,
 * the answer arrives in the template as `params.name`, already the right PHP type: a date param
 * is a `DateTime`, an element param is an element (or a list of them), a number is an `int` or
 * `float`. Templates should never have to parse a string that a form control produced.
 *
 * The type list is deliberately short. Every entry here is either a shape Craft's own CP has an
 * input for, or a shape an element query takes as a criteria value — because a parameter that
 * cannot be typed into the CP and cannot be fed to a query is a parameter nobody can use.
 */
class Parameter extends Model
{
    public const TYPE_TEXT = 'text';
    public const TYPE_NUMBER = 'number';
    public const TYPE_DATE = 'date';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_SELECT = 'select';
    public const TYPE_MULTISELECT = 'multiselect';
    public const TYPE_ENTRIES = 'entries';
    public const TYPE_USERS = 'users';
    public const TYPE_CATEGORIES = 'categories';
    public const TYPE_ASSETS = 'assets';
    public const TYPE_TAGS = 'tags';
    public const TYPE_SITE = 'site';

    public const ELEMENT_TYPES = [
        self::TYPE_ENTRIES => Entry::class,
        self::TYPE_USERS => User::class,
        self::TYPE_CATEGORIES => Category::class,
        self::TYPE_ASSETS => Asset::class,
        self::TYPE_TAGS => Tag::class,
    ];

    public string $name = '';
    public string $label = '';
    public string $type = self::TYPE_TEXT;
    public ?string $instructions = null;
    public bool $required = false;
    public bool $multiple = false;

    /** The default, in its *raw* form — what a form control would post. Normalised on read. */
    public mixed $default = null;

    /**
     * Options for select/multiselect, as `['value' => 'Label']`.
     *
     * Accepts the editable-table shape a CP form posts (`[['value' => …, 'label' => …], …]`) as
     * well as a plain map, because both arrive here and neither is wrong.
     *
     * @var array<string, string>
     */
    public array $options = [];

    public static function types(): array
    {
        return [
            self::TYPE_TEXT => Craft::t('reportr', 'Text'),
            self::TYPE_NUMBER => Craft::t('reportr', 'Number'),
            self::TYPE_DATE => Craft::t('reportr', 'Date'),
            self::TYPE_DATETIME => Craft::t('reportr', 'Date and time'),
            self::TYPE_BOOLEAN => Craft::t('reportr', 'Yes/no'),
            self::TYPE_SELECT => Craft::t('reportr', 'Dropdown'),
            self::TYPE_MULTISELECT => Craft::t('reportr', 'Checkboxes'),
            self::TYPE_ENTRIES => Craft::t('reportr', 'Entries'),
            self::TYPE_USERS => Craft::t('reportr', 'Users'),
            self::TYPE_CATEGORIES => Craft::t('reportr', 'Categories'),
            self::TYPE_ASSETS => Craft::t('reportr', 'Assets'),
            self::TYPE_TAGS => Craft::t('reportr', 'Tags'),
            self::TYPE_SITE => Craft::t('reportr', 'Site'),
        ];
    }

    public static function fromArray(array $config): self
    {
        $param = new self();

        $param->name = self::normalizeName((string)($config['name'] ?? ''));
        $param->label = trim((string)($config['label'] ?? '')) ?: StringHelper::toTitleCase($param->name);
        $param->type = (string)($config['type'] ?? self::TYPE_TEXT);
        $param->instructions = ($config['instructions'] ?? null) ?: null;
        $param->required = !empty($config['required']);
        $param->multiple = !empty($config['multiple']);
        $param->default = $config['default'] ?? null;
        $param->options = self::normalizeOptions($config['options'] ?? []);

        if (!isset(self::types()[$param->type])) {
            $param->type = self::TYPE_TEXT;
        }

        // A checkbox group is multiple by definition, and an element param that says "multiple"
        // has to keep saying it after a round trip through a form that only posts ticked boxes.
        if ($param->type === self::TYPE_MULTISELECT) {
            $param->multiple = true;
        }

        return $param;
    }

    /**
     * A parameter name has to survive being a Twig variable, a query-string key and a CLI option,
     * so it is reduced to the intersection of what all three allow rather than validated against
     * each of them separately.
     */
    public static function normalizeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]+/', '_', trim($name)) ?? '';
        $name = trim($name, '_');

        // A leading digit is legal in an array key and illegal as a Twig variable, which is the
        // one of the three that fails silently — `{{ params.2024total }}` is a parse error in a
        // template nobody edited.
        if ($name !== '' && ctype_digit($name[0])) {
            $name = 'p' . $name;
        }

        return $name;
    }

    /** @return array<string, string> */
    private static function normalizeOptions(mixed $options): array
    {
        // A single text box is a far better control for this than a nested table, so the edit
        // screen posts a string: one option per line, or comma separated, each optionally
        // `value: Label`.
        if (is_string($options)) {
            $options = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $options) ?: [])));
            $parsed = [];

            foreach ($options as $option) {
                $parts = explode(':', $option, 2);
                $value = trim($parts[0]);

                if ($value !== '') {
                    $parsed[$value] = trim($parts[1] ?? '') ?: $value;
                }
            }

            return $parsed;
        }

        if (!is_array($options)) {
            return [];
        }

        $normalized = [];

        foreach ($options as $key => $option) {
            if (is_array($option)) {
                $value = trim((string)($option['value'] ?? ''));
                $label = trim((string)($option['label'] ?? '')) ?: $value;

                if ($value !== '') {
                    $normalized[$value] = $label;
                }

                continue;
            }

            // A list of bare strings is what somebody writing this by hand in `reportr.php`
            // types first, and there is no reason to make them write a map.
            $value = is_int($key) ? (string)$option : (string)$key;
            $normalized[$value] = (string)$option;
        }

        return $normalized;
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'instructions' => $this->instructions,
            'required' => $this->required,
            'multiple' => $this->multiple,
            'default' => $this->default,
            'options' => $this->options,
        ];
    }

    /** The options as the edit screen's text box wants them: one `value: Label` per line. */
    public function getOptionsText(): string
    {
        $lines = [];

        foreach ($this->options as $value => $label) {
            $lines[] = $value === $label ? $value : $value . ': ' . $label;
        }

        return implode("\n", $lines);
    }

    public function getIsElementType(): bool
    {
        return isset(self::ELEMENT_TYPES[$this->type]);
    }

    public function getElementType(): ?string
    {
        return self::ELEMENT_TYPES[$this->type] ?? null;
    }

    /** Whether this parameter's answer can be more than one value. */
    public function getIsMultiple(): bool
    {
        return $this->multiple || $this->type === self::TYPE_MULTISELECT;
    }

    /**
     * Turn whatever was submitted into the value a template should see.
     *
     * Returns `null` for "not answered", which is distinct from an answer of `0`, `false` or `''`
     * — a report filtered on `params.includeDisabled` has to be able to tell "no" from "unasked".
     */
    public function normalize(mixed $raw): mixed
    {
        if ($raw === null || $raw === '' || (is_array($raw) && $raw === [])) {
            $raw = $this->default;
        }

        if ($raw === null || $raw === '') {
            return $this->type === self::TYPE_BOOLEAN ? false : null;
        }

        return match ($this->type) {
            self::TYPE_NUMBER => $this->normalizeNumber($raw),
            self::TYPE_DATE, self::TYPE_DATETIME => $this->normalizeDate($raw),
            self::TYPE_BOOLEAN => $this->normalizeBoolean($raw),
            self::TYPE_SELECT => $this->normalizeSelect($raw),
            self::TYPE_MULTISELECT => $this->normalizeMultiSelect($raw),
            self::TYPE_SITE => $this->normalizeSite($raw),
            default => $this->getIsElementType() ? $this->normalizeElements($raw) : $this->normalizeText($raw),
        };
    }

    /**
     * The value as it should be *stored* on the run, so a finished report can say what it was
     * asked. Elements become IDs; dates become ATOM strings; everything else is already scalar.
     */
    public function serialize(mixed $normalized): mixed
    {
        if ($normalized instanceof DateTime) {
            return $normalized->format(DATE_ATOM);
        }

        if (is_array($normalized)) {
            return array_values(array_map(fn($item) => $this->serialize($item), $normalized));
        }

        if ($normalized instanceof \craft\base\ElementInterface) {
            return $normalized->id;
        }

        return $normalized;
    }

    private function normalizeText(mixed $raw): ?string
    {
        if (is_array($raw)) {
            $raw = reset($raw);
        }

        $value = trim((string)$raw);

        return $value !== '' ? $value : null;
    }

    private function normalizeNumber(mixed $raw): int|float|null
    {
        if (is_array($raw)) {
            $raw = reset($raw);
        }

        // Craft's number field posts a localised string ("1,234.5" or "1.234,5"), so a bare
        // `(float)` cast reads the European form as 1.234 and silently changes the answer by three
        // orders of magnitude without erroring anywhere.
        if (is_string($raw)) {
            $raw = Localization::normalizeNumber($raw);
        }

        if (!is_numeric($raw)) {
            return null;
        }

        $number = $raw + 0;

        return is_int($number) ? $number : (float)$number;
    }

    /**
     * Dates arrive in three shapes and only one of them is a string.
     *
     * Craft's own date input posts `['date' => '7/1/2026', 'time' => '09:00', 'timezone' => …]`;
     * the command line posts `2026-07-01`; a Twig caller passes a `DateTime`. All three have to
     * land on the same instant, or a scheduled report and the same report run by hand disagree.
     */
    private function normalizeDate(mixed $raw): ?DateTime
    {
        // `assumeSystemTimeZone: true` — Craft's date input posts wall-clock time in the
        // site's timezone with no offset on it, and reading that as UTC moves the answer by
        // however many hours the server is from Greenwich. Which, west of it, is a different day.
        $date = DateTimeHelper::toDateTime($raw, true, true);

        if ($date === false) {
            // Relative expressions are the reason to run a report on a schedule at all —
            // "-7 days" has to mean seven days before *this* run, not before the day it was typed.
            if (is_string($raw)) {
                try {
                    return new DateTime($raw, new \DateTimeZone(Craft::$app->getTimeZone()));
                } catch (\Throwable) {
                    return null;
                }
            }

            return null;
        }

        return $date;
    }

    private function normalizeBoolean(mixed $raw): bool
    {
        if (is_array($raw)) {
            // Craft's checkbox posts a hidden `''` first and the ticked value second, so the
            // *last* value is the answer and the first is always a no.
            $raw = end($raw);
        }

        if (is_string($raw)) {
            return !in_array(mb_strtolower(trim($raw)), ['', '0', 'false', 'no', 'off', 'null'], true);
        }

        return (bool)$raw;
    }

    private function normalizeSelect(mixed $raw): ?string
    {
        $value = $this->normalizeText($raw);

        if ($value === null) {
            return null;
        }

        // An answer outside the declared options is a stale bookmark or a hand-edited URL, and
        // letting it through would put an unvalidated string into somebody's query.
        return array_key_exists($value, $this->options) ? $value : null;
    }

    /** @return string[] */
    private function normalizeMultiSelect(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [$raw];
        $values = array_map(static fn($value) => trim((string)$value), $values);

        return array_values(array_filter(
            array_unique($values),
            fn(string $value) => $value !== '' && array_key_exists($value, $this->options),
        ));
    }

    private function normalizeSite(mixed $raw): ?int
    {
        if (is_array($raw)) {
            $raw = reset($raw);
        }

        $sites = Craft::$app->getSites();
        $site = is_numeric($raw)
            ? $sites->getSiteById((int)$raw)
            : $sites->getSiteByHandle((string)$raw);

        return $site?->id;
    }

    /**
     * Element parameters resolve to elements, not IDs.
     *
     * A template that has to write `craft.entries.id(params.section).one()` has been handed a
     * chore, and one it will get wrong on the day the parameter is empty.
     */
    private function normalizeElements(mixed $raw): mixed
    {
        $elementType = $this->getElementType();
        $ids = is_array($raw) ? $raw : [$raw];
        $ids = array_values(array_filter(array_map('intval', array_filter($ids, 'is_numeric'))));

        if ($ids === []) {
            return $this->getIsMultiple() ? [] : null;
        }

        /** @var \craft\elements\db\ElementQuery $query */
        $query = $elementType::find();
        $query->id($ids)
            ->status(null)
            ->fixedOrder()
            ->limit($this->getIsMultiple() ? null : 1);

        if ($elementType === Asset::class) {
            $query->kind(null);
        }

        $elements = $query->all();

        return $this->getIsMultiple() ? $elements : ($elements[0] ?? null);
    }

    /** A one-line human rendering of an answer, for the run detail screen and the email. */
    public function describe(mixed $normalized): string
    {
        if ($normalized === null) {
            return Craft::t('reportr', '(none)');
        }

        if ($normalized instanceof DateTime) {
            return Craft::$app->getFormatter()->asDatetime(
                $normalized,
                $this->type === self::TYPE_DATE ? 'short' : 'medium',
            );
        }

        if (is_bool($normalized)) {
            return $normalized ? Craft::t('app', 'Yes') : Craft::t('app', 'No');
        }

        if ($normalized instanceof \craft\base\ElementInterface) {
            return (string)$normalized;
        }

        if (is_array($normalized)) {
            if ($normalized === []) {
                return Craft::t('reportr', '(none)');
            }

            return implode(', ', array_map(fn($item) => $this->describe($item), $normalized));
        }

        if ($this->type === self::TYPE_SELECT) {
            return $this->options[(string)$normalized] ?? (string)$normalized;
        }

        if ($this->type === self::TYPE_SITE) {
            return Craft::$app->getSites()->getSiteById((int)$normalized)?->name ?? (string)$normalized;
        }

        return (string)$normalized;
    }
}
