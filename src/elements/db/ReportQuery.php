<?php

declare(strict_types=1);

namespace justinholtweb\reportr\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use DateTime;
use justinholtweb\reportr\elements\Report;

/**
 * @method Report[] all($db = null)
 * @method Report|null one($db = null)
 * @method Report|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class ReportQuery extends ElementQuery
{
    /**
     * Every param here is `mixed`, not a narrower type.
     *
     * Craft builds an element-index source's query with `Craft::configure()`, which assigns
     * *straight to the public property* rather than calling the setter of the same name. A param
     * typed `?string` against a source declaring `['type' => 'basic']` is fine; one typed `?array`
     * against `['isScheduled' => true]` throws "Cannot assign bool to property … of type ?array"
     * and takes the whole index down — for the one source nobody clicked while testing.
     */
    public mixed $handle = null;
    public mixed $type = null;
    public mixed $legacyId = null;
    public mixed $isScheduled = null;

    /** Reports whose stored next-run time has arrived. The scheduler's only question. */
    public mixed $dueBefore = null;

    protected array $defaultOrderBy = ['reportr_reports.handle' => SORT_ASC];

    public function handle(mixed $value): static
    {
        $this->handle = $value;

        return $this;
    }

    public function type(mixed $value): static
    {
        $this->type = $value;

        return $this;
    }

    public function legacyId(mixed $value): static
    {
        $this->legacyId = $value;

        return $this;
    }

    public function isScheduled(mixed $value = true): static
    {
        $this->isScheduled = $value;

        return $this;
    }

    public function dueBefore(mixed $value): static
    {
        $this->dueBefore = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('reportr_reports');

        $this->query->addSelect([
            'reportr_reports.handle',
            'reportr_reports.type',
            'reportr_reports.description',
            'reportr_reports.template',
            'reportr_reports.formatFunction',
            'reportr_reports.format',
            'reportr_reports.formatOptions',
            'reportr_reports.params',
            'reportr_reports.querySpec',
            'reportr_reports.filenameFormat',
            'reportr_reports.fsHandle',
            'reportr_reports.fsSubpath',
            'reportr_reports.schedule',
            'reportr_reports.nextRunAt',
            'reportr_reports.lastRunAt',
            'reportr_reports.delivery',
            'reportr_reports.retentionRuns',
            'reportr_reports.retentionDays',
            'reportr_reports.batchSize',
            'reportr_reports.runCount',
            'reportr_reports.sortOrder',
            'reportr_reports.legacyId',
        ]);

        if ($this->handle !== null) {
            $this->subQuery->andWhere(Db::parseParam('reportr_reports.handle', $this->handle));
        }

        if ($this->type !== null) {
            $this->subQuery->andWhere(Db::parseParam('reportr_reports.type', $this->type));
        }

        if ($this->legacyId !== null) {
            $this->subQuery->andWhere(Db::parseParam('reportr_reports.legacyId', $this->legacyId));
        }

        if ($this->isScheduled !== null) {
            $this->subQuery->andWhere($this->isScheduled
                ? ['not', ['reportr_reports.nextRunAt' => null]]
                : ['reportr_reports.nextRunAt' => null]);
        }

        if ($this->dueBefore !== null) {
            $moment = $this->dueBefore instanceof DateTime ? $this->dueBefore : new DateTime('now');

            $this->subQuery->andWhere(['and',
                ['not', ['reportr_reports.nextRunAt' => null]],
                ['<=', 'reportr_reports.nextRunAt', Db::prepareDateForDb($moment)],
            ]);
        }

        return true;
    }
}
