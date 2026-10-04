<?php

declare(strict_types=1);

namespace justinholtweb\reportr\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\errors\InvalidFieldException;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craft\services\ElementSources;
use DateTimeInterface;
use justinholtweb\reportr\models\Column;
use justinholtweb\reportr\models\QuerySpec;
use Throwable;

/**
 * Turns a {@see QuerySpec} into an element query, and an element into a row.
 *
 * This is the half of the plugin Lab Reports does not have at all: a report that a person who
 * does not write Twig can build, edit and re-point without a deployment. It is deliberately built
 * *on top of Craft's own index sources* rather than on a filter UI of its own — the source list
 * on this screen is the same list on the entries index, so "Recent orders" means the same thing
 * in both places and keeps meaning it when somebody edits the source.
 */
class QueryBuilder extends Component
{
    /** What an order-by may be: a column, optionally table-qualified. */
    public const ORDER_BY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /**
     * Path segments no column may read. Lower case.
     *
     * Credentials, plus the getters that do work rather than read a value: `copyOfFile` writes a
     * temporary file per row, `stream`/`contents`/`dataUrl` read a whole asset into memory.
     */
    public const DENIED_SEGMENTS = [
        'password', 'newpassword', 'currentpassword', 'verificationcode', 'authkey', 'securitykey',
        'copyoffile', 'stream', 'contents', 'dataurl', 'fs', 'transformfs',
    ];

    /**
     * Element-query params a report parameter of the same name is applied to — and the only ones.
     *
     * Before this, any parameter named after a query *property* was assigned to it, which made
     * `where` and `orderBy` raw SQL, and `editable` or `sectionId` a way past the report's source.
     * These are filters a person running a report would reasonably narrow by; nothing here is SQL,
     * and the ones a source also sets are intersected with it ({@see ID_PARAMS}).
     */
    public const QUERY_PARAMS = [
        'postDate', 'expiryDate', 'dateCreated', 'dateUpdated', 'lastLoginDate',
        'relatedTo', 'search', 'level', 'kind',
        'sectionId', 'typeId', 'authorId', 'authorGroupId', 'groupId', 'volumeId', 'folderId',
    ];

    /**
     * Query params that an index source may itself set to define what it shows. A parameter can
     * only narrow these: its answer is intersected with the source's, never put in its place.
     */
    public const ID_PARAMS = ['sectionId', 'typeId', 'authorId', 'authorGroupId', 'groupId', 'volumeId', 'folderId'];

    /**
     * Objects that are not elements but that a column may still read scalars from, one step past
     * an element: `section.handle`, `type.name`, `volume.handle`, `site.language`. Nothing else
     * that an element getter returns is walked — `fs.secret` is why.
     */
    private const READABLE_MODELS = [
        \craft\models\Section::class,
        \craft\models\EntryType::class,
        \craft\models\Volume::class,
        \craft\models\VolumeFolder::class,
        \craft\models\Site::class,
        \craft\models\SiteGroup::class,
        \craft\models\CategoryGroup::class,
        \craft\models\TagGroup::class,
        \craft\models\UserGroup::class,
    ];

    /**
     * Attributes offered for every element type, on top of whatever the type itself lists.
     *
     * `url` and `status` are here rather than derived because they are the two people look for
     * first and neither is a database column.
     */
    private const CORE_ATTRIBUTES = [
        'id' => 'ID',
        'title' => 'Title',
        'slug' => 'Slug',
        'uri' => 'URI',
        'url' => 'URL',
        'status' => 'Status',
        'siteId' => 'Site ID',
        'dateCreated' => 'Date created',
        'dateUpdated' => 'Date updated',
    ];

    private const TYPE_ATTRIBUTES = [
        Entry::class => [
            'sectionId' => 'Section ID',
            'section.handle' => 'Section handle',
            'section.name' => 'Section name',
            'type.handle' => 'Entry type handle',
            'type.name' => 'Entry type',
            'postDate' => 'Post date',
            'expiryDate' => 'Expiry date',
            'author.username' => 'Author username',
            'author.email' => 'Author email',
            'author.fullName' => 'Author name',
        ],
        \craft\elements\User::class => [
            'username' => 'Username',
            'email' => 'Email',
            'fullName' => 'Full name',
            'firstName' => 'First name',
            'lastName' => 'Last name',
            'lastLoginDate' => 'Last login',
            'active' => 'Active',
            'pending' => 'Pending',
        ],
        Asset::class => [
            'filename' => 'Filename',
            'extension' => 'Extension',
            'kind' => 'Kind',
            'size' => 'Size (bytes)',
            'width' => 'Width',
            'height' => 'Height',
            'alt' => 'Alt text',
            'volume.handle' => 'Volume handle',
        ],
    ];

    /**
     * Build the element query a query report should run.
     *
     * @param array<string, mixed> $params Normalised parameter answers.
     */
    public function build(QuerySpec $spec, array $params = []): ElementQuery
    {
        if (!class_exists($spec->elementType)) {
            throw new \RuntimeException("The element type “{$spec->elementType}” is not installed.");
        }

        /** @var class-string<ElementInterface> $elementType */
        $elementType = $spec->elementType;

        /** @var ElementQuery $query */
        $query = $elementType::find();

        $this->applySource($query, $spec);

        // Status after the source, deliberately. A source may set a status of its own and an
        // explicit choice on the report has to win over it.
        if ($spec->status !== null) {
            $query->status($spec->status === 'any' ? null : $spec->status);
        }

        if ($spec->search !== null && trim($spec->search) !== '') {
            $query->search($this->interpolate($spec->search, $params));
        }

        if ($spec->site !== null) {
            $query->siteId($spec->site === '*' ? '*' : $spec->site);
        }

        // A column name, never an expression: Yii passes anything with a parenthesis through to
        // the SQL unquoted, and the order is a field a report author types.
        if ($spec->orderBy !== null && preg_match(self::ORDER_BY_PATTERN, trim($spec->orderBy))) {
            $query->orderBy([trim($spec->orderBy) => $spec->direction === 'desc' ? SORT_DESC : SORT_ASC]);
        }

        if ($spec->limit !== null) {
            $query->limit($spec->limit);
        }

        $this->applyParams($query, $params);

        return $query;
    }

    /**
     * Let parameters filter the query without anybody writing a query.
     *
     * A parameter named after one of {@see QUERY_PARAMS} is applied to the query through its own
     * method — so a report with a `postDate` or `authorId` parameter is filterable at run time with
     * no configuration. Any other name is still available to a `twig:` column and to templates;
     * it just never touches the query.
     *
     * An answer can only narrow what the report's source shows. Where the source already set one of
     * {@see ID_PARAMS}, the answer is intersected with it, and an answer outside it matches nothing.
     */
    private function applyParams(ElementQuery $query, array $params): void
    {
        foreach ($params as $name => $value) {
            $name = (string)$name;
            // Called through a plain string: the methods live on the element type's own query
            // class, which is only known at run time.
            $method = $name;

            if ($value === null || $value === [] || $value === '' || !in_array($name, self::QUERY_PARAMS, true)) {
                continue;
            }

            if ($name !== 'relatedTo' && !method_exists($query, $name)) {
                continue;
            }

            try {
                if ($name === 'relatedTo') {
                    // `andRelatedTo`: a source can relate too, and this must add to it, not replace it.
                    $query->andRelatedTo($value);

                    continue;
                }

                if (in_array($name, self::ID_PARAMS, true)) {
                    $ids = $this->ids($value);

                    if ($ids === null || $ids === []) {
                        continue;
                    }

                    $existing = property_exists($query, $name) ? $query->{$name} : null;

                    if ($existing !== null && $existing !== '' && $existing !== []) {
                        $allowed = $this->ids($existing);

                        if ($allowed === null) {
                            // The source set it to something other than a list of IDs — `not 3`,
                            // a wildcard. Not something to intersect with safely, so the source wins.
                            Craft::warning("Reportr ignored the “{$name}” parameter: the report’s source already sets it.", 'reportr');

                            continue;
                        }

                        $ids = array_values(array_intersect($ids, $allowed)) ?: [0];
                    }

                    $query->$method($ids);

                    continue;
                }

                $query->$method($value);
            } catch (Throwable $e) {
                Craft::warning("Reportr could not apply the “{$name}” parameter to the query: " . $e->getMessage(), 'reportr');
            }
        }
    }

    /**
     * A parameter answer or a criteria value as a list of integer IDs, or null if it isn't one.
     *
     * @return int[]|null
     */
    private function ids(mixed $value): ?array
    {
        $ids = [];

        foreach (is_array($value) ? $value : [$value] as $item) {
            if ($item instanceof ElementInterface) {
                $item = $item->id;
            }

            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                $ids[] = (int)$item;

                continue;
            }

            return null;
        }

        return array_values(array_unique($ids));
    }

    private function applySource(ElementQuery $query, QuerySpec $spec): void
    {
        if ($spec->source === '' || $spec->source === '*') {
            return;
        }

        // Not the index context: Craft builds that around whoever is signed in and adds
        // `editable: true`, so a build from cron or a console queue runner — nobody signed in —
        // aborted with no rows, and one from a web queue ran with the reach of whichever visitor
        // happened to trigger it. Who may report on a source is settled when the report is saved
        // (`Report::validateAccess()`); the build runs what was saved.
        $source = ElementHelper::findSource($spec->elementType, $spec->source, ElementSources::CONTEXT_FIELD);

        if ($source === null) {
            // The section was deleted, or the source was renamed. Running the report against
            // *everything* would quietly turn "the newsletter subscribers" into "every user", so
            // it fails loudly instead.
            throw new \RuntimeException("The source “{$spec->source}” no longer exists.");
        }

        if (!empty($source['criteria'])) {
            $criteria = $source['criteria'];
            unset($criteria['editable'], $criteria['savable']);
            Craft::configure($query, $criteria);
        }

        if (!empty($source['condition'])) {
            $condition = Craft::$app->getConditions()->createCondition($source['condition']);

            if ($condition instanceof \craft\elements\conditions\ElementConditionInterface) {
                $condition->modifyQuery($query);
            }
        }
    }

    /** One element, one row. */
    public function row(ElementInterface $element, QuerySpec $spec): array
    {
        $row = [];

        foreach ($spec->columns as $column) {
            $row[] = $this->format($this->resolve($element, $column), $column);
        }

        return $row;
    }

    /** The raw value behind a column, before formatting. */
    public function resolve(ElementInterface $element, Column $column): mixed
    {
        [$prefix, $path] = $column->parse();

        try {
            return match ($prefix) {
                Column::PREFIX_TWIG => Craft::$app->getView()->renderObjectTemplate(
                    $path,
                    $element,
                    // `renderObjectTemplate()` calls its subject `object`, which nobody guesses.
                    // `element` is passed alongside because that is what everyone types first.
                    ['element' => $element],
                ),
                Column::PREFIX_FIELD => $this->walk($this->fieldValue($element, $path), $this->rest($path)),
                default => $this->walk($element, $path),
            };
        } catch (Throwable $e) {
            Craft::warning(sprintf(
                'Reportr could not resolve the column “%s” on %s %s: %s',
                $column->key,
                $element::displayName(),
                $element->id,
                $e->getMessage(),
            ), 'reportr');

            return null;
        }
    }

    private function fieldValue(ElementInterface $element, string $path): mixed
    {
        $handle = explode('.', $path)[0];

        try {
            return $element->getFieldValue($handle);
        } catch (InvalidFieldException) {
            // A field the element type does not have is a blank cell, not a failed report. A
            // report over a mixed source hits this on every row of the other entry type.
            return null;
        }
    }

    private function rest(string $path): string
    {
        $dot = strpos($path, '.');

        return $dot === false ? '' : substr($path, $dot + 1);
    }

    /**
     * Walk a dotted path, resolving queries and fanning out over lists.
     *
     * The fan-out is what makes `field:relatedBooks.title` and `field:priceTable.amount` work,
     * which between them are Lab Reports issue #3 — where the answer given was "write more Twig".
     */
    private function walk(mixed $value, string $path): mixed
    {
        if ($path === '') {
            return $value;
        }

        foreach (explode('.', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            $value = $this->step($value, $segment);

            if ($value === null) {
                return null;
            }
        }

        return $value;
    }

    /**
     * One segment of a column path.
     *
     * What may be walked into is deliberately narrow, because a path is typed by whoever manages
     * reports and every getter on every object it reaches is otherwise theirs to call: elements,
     * element queries, lists and plain arrays, field values, and — one step past an element — the
     * handful of {@see READABLE_MODELS}. From anything that isn't an element, only scalars, dates
     * and elements come back. `attr:volume.fs.secret` is the path this exists to stop.
     */
    private function step(mixed $value, string $segment): mixed
    {
        // A user's password hash, verification code and the like are never report data, however
        // the path reaches them (`attr:author.password`).
        if (in_array(strtolower($segment), self::DENIED_SEGMENTS, true)) {
            return null;
        }

        if ($value instanceof ElementQuery) {
            $value = $value->all();
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            // A keyed row — a Table field's, say — is read by key.
            if (!array_is_list($value)) {
                return array_key_exists($segment, $value) ? $this->admit($value[$segment], false) : null;
            }

            // A numeric segment indexes into the list; anything else is read from every item.
            if (ctype_digit($segment)) {
                return $value[(int)$segment] ?? null;
            }

            $mapped = [];

            foreach ($value as $item) {
                $resolved = $this->step($item, $segment);

                if ($resolved !== null) {
                    $mapped[] = $resolved;
                }
            }

            return $mapped;
        }

        if ($value instanceof ElementInterface) {
            // `getFieldValue()` first: a field and an attribute can share a handle, and on an
            // entry the field is what the author means by that word. A field's value is content,
            // whatever shape the field type gives it.
            try {
                return $value->getFieldValue($segment);
            } catch (Throwable) {
                // Not a field. Fall through to the attribute.
            }

            return $this->admit($this->read($value, $segment), true);
        }

        if (is_object($value)) {
            return $this->admit($this->read($value, $segment), false);
        }

        return null;
    }

    private function read(object $value, string $segment): mixed
    {
        $getter = 'get' . ucfirst($segment);

        if (method_exists($value, $getter)) {
            return $value->$getter();
        }

        if (isset($value->$segment) || property_exists($value, $segment)) {
            return $value->$segment;
        }

        // Yii magic properties are neither, and `isset()` on one whose getter returns null is
        // false — so a last attempt, guarded, rather than declaring the path dead.
        try {
            return $value->$segment;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What a step may hand on: scalars, dates, elements, element queries, lists of those — and,
     * straight off an element, one of {@see READABLE_MODELS}. Everything else stops the path.
     */
    private function admit(mixed $value, bool $fromElement): mixed
    {
        if ($value === null || is_scalar($value) || $value instanceof DateTimeInterface
            || $value instanceof ElementInterface || $value instanceof ElementQuery) {
            return $value;
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            $admitted = [];

            // A list straight off an element — a user's groups — is as readable as one of them.
            foreach ($value as $key => $item) {
                $item = $this->admit($item, $fromElement);

                if ($item !== null) {
                    $admitted[$key] = $item;
                }
            }

            return array_is_list($value) ? array_values($admitted) : $admitted;
        }

        if ($fromElement && is_object($value)) {
            foreach (self::READABLE_MODELS as $class) {
                if ($value instanceof $class) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function format(mixed $value, Column $column): mixed
    {
        if ($value === null) {
            return '';
        }

        $formatter = Craft::$app->getFormatter();

        return match ($column->format) {
            Column::FORMAT_DATE => $value instanceof DateTimeInterface
                ? ($column->dateFormat ? $value->format($column->dateFormat) : $formatter->asDate($value, 'short'))
                : $value,
            Column::FORMAT_DATETIME => $value instanceof DateTimeInterface
                ? ($column->dateFormat ? $value->format($column->dateFormat) : $formatter->asDatetime($value, 'short'))
                : $value,
            Column::FORMAT_NUMBER => is_numeric($value) ? $value + 0 : $value,
            Column::FORMAT_BOOLEAN => $value ? Craft::t('app', 'Yes') : Craft::t('app', 'No'),
            Column::FORMAT_LIST => $this->joinList($value, $column->separator),
            Column::FORMAT_TEXT => is_array($value) ? $this->joinList($value, $column->separator) : (string)$value,
            default => is_array($value) ? $this->joinList($value, $column->separator) : $value,
        };
    }

    private function joinList(mixed $value, string $separator): string
    {
        if (!is_array($value)) {
            return (string)$this->scalarize($value);
        }

        return implode($separator, array_map(fn($item) => (string)$this->scalarize($item), $value));
    }

    private function scalarize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn($item) => (string)$this->scalarize($item), $value));
        }

        return is_scalar($value) ? $value : '';
    }

    /** Replace `{paramName}` in a search term with the parameter's answer. */
    private function interpolate(string $subject, array $params): string
    {
        return preg_replace_callback('/\{(\w+)\}/', static function(array $matches) use ($params): string {
            $value = $params[$matches[1]] ?? null;

            if ($value === null) {
                return '';
            }

            if (is_array($value)) {
                return implode(' ', array_map('strval', $value));
            }

            return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string)$value;
        }, $subject) ?? $subject;
    }

    // Options for the edit screen
    // -------------------------------------------------------------------------

    /** @return array<string, string> Class => plural label. */
    public function elementTypeOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getElements()->getAllElementTypes() as $type) {
            /** @var class-string<ElementInterface> $type */
            $options[$type] = $type::pluralDisplayName();
        }

        asort($options);

        return $options;
    }

    /** @return array<int, array{label: string, value: string}> */
    public function sourceOptions(string $elementType): array
    {
        $options = [['label' => Craft::t('reportr', 'All {type}', ['type' => '']) ?: 'All', 'value' => '*']];

        if (!class_exists($elementType)) {
            return $options;
        }

        try {
            $sources = Craft::$app->getElementSources()->getSources($elementType, ElementSources::CONTEXT_INDEX);
        } catch (Throwable) {
            return $options;
        }

        $heading = null;

        foreach ($sources as $source) {
            if (isset($source['heading'])) {
                $heading = (string)$source['heading'];

                continue;
            }

            if (!isset($source['key']) || $source['key'] === '*') {
                continue;
            }

            $label = (string)($source['label'] ?? $source['key']);

            $options[] = [
                'label' => $heading !== null ? $heading . ' — ' . $label : $label,
                'value' => (string)$source['key'],
            ];
        }

        return $options;
    }

    /**
     * Every column key the picker offers, as select options.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function columnOptions(string $elementType): array
    {
        $options = [];

        foreach (self::CORE_ATTRIBUTES as $key => $label) {
            $options[] = ['label' => $label, 'value' => 'attr:' . $key];
        }

        foreach (self::TYPE_ATTRIBUTES[$elementType] ?? [] as $key => $label) {
            $options[] = ['label' => $label, 'value' => 'attr:' . $key];
        }

        $fields = [];

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            /** @var FieldInterface $field */
            $fields[] = [
                'label' => Craft::t('reportr', 'Field: {name}', ['name' => $field->name]),
                'value' => 'field:' . $field->handle,
            ];
        }

        usort($fields, static fn(array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return array_merge($options, $fields);
    }

    /** A readable name for a column key, used when a report is described rather than run. */
    public function describeKey(string $elementType, string $key): string
    {
        foreach ($this->columnOptions($elementType) as $option) {
            if ($option['value'] === $key) {
                return $option['label'];
            }
        }

        return StringHelper::toTitleCase(str_replace([':', '.'], ' ', $key));
    }
}
