<?php

declare(strict_types=1);

namespace justinholtweb\reportr\twig;

use Craft;
use justinholtweb\reportr\elements\db\ReportQuery;
use justinholtweb\reportr\elements\db\RunQuery;
use justinholtweb\reportr\elements\Report;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\Plugin;

/**
 * `craft.reportr` in templates.
 *
 * The Lab Reports names — `configuredReports`, `generatedReports`, `formatFunctionNames`,
 * `formatFunctionOptions` — are all here as aliases, so a front-end template that lists a site's
 * reports keeps working after `craft.labreports` is changed to `craft.reportr` and nothing else.
 */
class ReportrVariable
{
    /** @param array<string, mixed> $criteria */
    public function reports(array $criteria = []): ReportQuery
    {
        return Plugin::getInstance()->reports->query($criteria);
    }

    /** Lab Reports' name for the same query. */
    public function configuredReports(array $criteria = []): ReportQuery
    {
        return $this->reports($criteria);
    }

    /** @param array<string, mixed> $criteria */
    public function runs(array $criteria = []): RunQuery
    {
        /** @var RunQuery $query */
        $query = Run::find();

        if ($criteria !== []) {
            Craft::configure($query, $criteria);
        }

        return $query;
    }

    /** Lab Reports' name for the same query. */
    public function generatedReports(array $criteria = []): RunQuery
    {
        return $this->runs($criteria);
    }

    public function report(string|int $identifier): ?Report
    {
        return Plugin::getInstance()->reports->getReport($identifier);
    }

    public function run(int $id): ?Run
    {
        /** @var Run|null */
        return Run::find()->id($id)->status(null)->one();
    }

    /** @return string[] */
    public function formatFunctionNames(): array
    {
        return Plugin::getInstance()->reports->formatFunctionNames();
    }

    /** @return array<string, string> */
    public function formatFunctionOptions(): array
    {
        return Plugin::getInstance()->reports->formatFunctionOptions();
    }

    /** @return array<string, string> */
    public function formats(): array
    {
        return Plugin::getInstance()->formats->options(true);
    }

    /**
     * Queue a report from a template, with parameters.
     *
     * Deliberately queues rather than builds: a template that blocks a page load for the length
     * of an export is a page that times out. The returned run is queued, and its detail page
     * shows the progress.
     *
     * @param array<string, mixed> $params
     */
    public function queue(string|int $identifier, array $params = []): ?Run
    {
        $report = $this->report($identifier);

        if ($report === null) {
            return null;
        }

        return Plugin::getInstance()->runner->enqueue($report, $params, Run::INITIATOR_TEMPLATE);
    }
}
