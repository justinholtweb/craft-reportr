<?php

declare(strict_types=1);

namespace justinholtweb\reportr\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use justinholtweb\reportr\elements\Run;

/**
 * @method Run[] all($db = null)
 * @method Run|null one($db = null)
 * @method Run|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class RunQuery extends ElementQuery
{
    public mixed $reportId = null;
    public mixed $filename = null;
    public mixed $initiator = null;
    public mixed $userId = null;
    public mixed $dateFinished = null;
    public mixed $legacyId = null;

    /**
     * Lab Reports' names for two of these, kept so that a front-end template written against
     * `craft.labreports.generatedReports` keeps working after a find-and-replace of the variable.
     */
    public mixed $configuredReportId = null;
    public mixed $dateGenerated = null;

    protected array $defaultOrderBy = ['reportr_runs.dateCreated' => SORT_DESC];

    public function reportId(mixed $value): static
    {
        $this->reportId = $value;

        return $this;
    }

    public function configuredReportId(mixed $value): static
    {
        $this->configuredReportId = $value;

        return $this;
    }

    public function report(mixed $value): static
    {
        if (is_string($value)) {
            $report = \justinholtweb\reportr\elements\Report::find()->handle($value)->status(null)->one();

            // No match must return nothing, not everything. `false` is Craft's convention for a
            // parameter that can never be satisfied.
            $this->reportId = $report?->id ?? false;

            return $this;
        }

        if ($value instanceof \justinholtweb\reportr\elements\Report) {
            $this->reportId = $value->id;

            return $this;
        }

        $this->reportId = $value;

        return $this;
    }

    public function filename(mixed $value): static
    {
        $this->filename = $value;

        return $this;
    }

    public function initiator(mixed $value): static
    {
        $this->initiator = $value;

        return $this;
    }

    public function userId(mixed $value): static
    {
        $this->userId = $value;

        return $this;
    }

    public function dateFinished(mixed $value): static
    {
        $this->dateFinished = $value;

        return $this;
    }

    public function dateGenerated(mixed $value): static
    {
        $this->dateGenerated = $value;

        return $this;
    }

    public function legacyId(mixed $value): static
    {
        $this->legacyId = $value;

        return $this;
    }

    /**
     * A run's status is its own column, not the element's enabled flag.
     *
     * Without this override `status('finished')` silently matches nothing — `ElementQuery` would
     * be looking for an `enabled` value called "finished" — so every status source on the index
     * would come back empty with no error anywhere.
     */
    protected function statusCondition(string $status): mixed
    {
        return in_array($status, [Run::STATUS_QUEUED, Run::STATUS_RUNNING, Run::STATUS_FINISHED, Run::STATUS_ERROR], true)
            ? ['reportr_runs.runStatus' => $status]
            : parent::statusCondition($status);
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('reportr_runs');

        $this->query->addSelect([
            'reportr_runs.reportId',
            'reportr_runs.reportTitle',
            'reportr_runs.runStatus',
            'reportr_runs.statusMessage',
            'reportr_runs.format',
            'reportr_runs.filename',
            'reportr_runs.fsHandle',
            'reportr_runs.path',
            'reportr_runs.fileSize',
            'reportr_runs.totalRows',
            'reportr_runs.params',
            'reportr_runs.initiator',
            'reportr_runs.userId',
            'reportr_runs.dateStarted',
            'reportr_runs.dateFinished',
            'reportr_runs.durationMs',
            'reportr_runs.peakMemory',
            'reportr_runs.legacyId',
        ]);

        $reportId = $this->reportId ?? $this->configuredReportId;

        if ($reportId !== null) {
            $this->subQuery->andWhere(Db::parseParam('reportr_runs.reportId', $reportId));
        }

        if ($this->filename !== null) {
            $this->subQuery->andWhere(Db::parseParam('reportr_runs.filename', $this->filename));
        }

        if ($this->initiator !== null) {
            $this->subQuery->andWhere(Db::parseParam('reportr_runs.initiator', $this->initiator));
        }

        if ($this->userId !== null) {
            $this->subQuery->andWhere(Db::parseParam('reportr_runs.userId', $this->userId));
        }

        if ($this->legacyId !== null) {
            $this->subQuery->andWhere(Db::parseParam('reportr_runs.legacyId', $this->legacyId));
        }

        $finished = $this->dateFinished ?? $this->dateGenerated;

        if ($finished !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('reportr_runs.dateFinished', $finished));
        }

        return true;
    }
}
