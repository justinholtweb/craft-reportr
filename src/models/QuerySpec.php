<?php

declare(strict_types=1);

namespace justinholtweb\reportr\models;

use Craft;
use craft\base\Model;
use craft\elements\Entry;

/**
 * A report with no template at all.
 *
 * Lab Reports is a developer's tool end to end: every report is a Twig file in the repository, so
 * "export the orders from last quarter" is a deployment. That is the right shape for the hard
 * reports and completely the wrong shape for the ordinary ones, which is why this exists
 * alongside the template types rather than instead of them.
 *
 * A query report is an element query somebody built in the control panel — element type, one of
 * that type's own index sources, a status, an order — plus a list of {@see Column}s. Nothing here
 * is a new query language: the source keys come from `craft\services\ElementSources`, so a report
 * can be pointed at exactly the same "Recent orders" source the index shows, and it will keep
 * meaning the same thing when somebody edits that source.
 */
class QuerySpec extends Model
{
    public string $elementType = Entry::class;

    /** An element index source key — `section:<uid>`, `group:<uid>`, `*`, and so on. */
    public string $source = '*';

    /** `null` here means "whatever the element type's default is", not "any status". */
    public ?string $status = null;

    public ?string $search = null;
    public ?string $orderBy = null;
    public string $direction = 'asc';
    public ?int $limit = null;

    /** A site handle, an ID, `*` for every site, or null for the current one. */
    public ?string $site = null;

    /** @var Column[] */
    public array $columns = [];

    public static function fromArray(?array $config): self
    {
        $spec = new self();

        if ($config === null) {
            return $spec;
        }

        $elementType = (string)($config['elementType'] ?? Entry::class);

        // A report pointed at an element type whose plugin has since been uninstalled must not
        // fatal the edit screen — it falls back to entries and says so when it is run.
        $spec->elementType = class_exists($elementType) ? $elementType : Entry::class;
        $spec->source = (string)($config['source'] ?? '*') ?: '*';
        $spec->status = ($config['status'] ?? null) ?: null;
        $spec->search = ($config['search'] ?? null) ?: null;
        $spec->orderBy = ($config['orderBy'] ?? null) ?: null;
        $spec->direction = ($config['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $spec->limit = isset($config['limit']) && (int)$config['limit'] > 0 ? (int)$config['limit'] : null;
        $spec->site = ($config['site'] ?? null) ?: null;

        foreach ($config['columns'] ?? [] as $columnConfig) {
            if (!is_array($columnConfig)) {
                continue;
            }

            $column = Column::fromArray($columnConfig);

            if ($column->key !== '') {
                $spec->columns[] = $column;
            }
        }

        return $spec;
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'elementType' => $this->elementType,
            'source' => $this->source,
            'status' => $this->status,
            'search' => $this->search,
            'orderBy' => $this->orderBy,
            'direction' => $this->direction,
            'limit' => $this->limit,
            'site' => $this->site,
            'columns' => array_map(static fn(Column $column) => $column->toArray(), $this->columns),
        ];
    }

    /** @return string[] */
    public function headings(): array
    {
        return array_map(static fn(Column $column) => $column->heading, $this->columns);
    }

    public function getIsUsable(): bool
    {
        return $this->columns !== [] && class_exists($this->elementType);
    }

    public function getElementTypeLabel(): string
    {
        if (!class_exists($this->elementType)) {
            return $this->elementType;
        }

        /** @var class-string<\craft\base\ElementInterface> $type */
        $type = $this->elementType;

        return $type::pluralDisplayName();
    }
}
