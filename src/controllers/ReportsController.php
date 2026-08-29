<?php

declare(strict_types=1);

namespace justinholtweb\reportr\controllers;

use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\reportr\elements\Report;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\models\Column;
use justinholtweb\reportr\models\Delivery;
use justinholtweb\reportr\models\FormatOptions;
use justinholtweb\reportr\models\Parameter;
use justinholtweb\reportr\models\QuerySpec;
use justinholtweb\reportr\models\Schedule;
use justinholtweb\reportr\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The report configuration screens.
 */
class ReportsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW_REPORTS);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('reportr/reports/_index', [
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_REPORTS),
        ]);
    }

    public function actionEdit(?int $reportId = null, ?Report $report = null): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE_REPORTS);

        // `$report` arrives already populated when this is re-rendered after a failed save, so
        // the author's unsaved edits survive the round trip instead of being read back from the
        // database and quietly discarded.
        if ($report === null) {
            $report = $reportId !== null
                ? Plugin::getInstance()->reports->getReportById($reportId)
                : new Report();

            if ($report === null) {
                throw new NotFoundHttpException('Report not found.');
            }
        }

        $plugin = Plugin::getInstance();
        $spec = $report->getQuerySpec();

        return $this->renderTemplate('reportr/reports/_edit', [
            'report' => $report,
            'isNew' => $report->id === null,
            'title' => $report->id !== null ? $report->title : Craft::t('reportr', 'New report'),
            'typeOptions' => [
                Report::TYPE_BASIC => Craft::t('reportr', 'Basic — a template that builds an array of rows'),
                Report::TYPE_ADVANCED => Craft::t('reportr', 'Advanced — a template plus a PHP formatting function'),
                Report::TYPE_QUERY => Craft::t('reportr', 'Query — built here, no template needed'),
            ],
            'formatOptions' => $plugin->formats->options(),
            'functionOptions' => $plugin->reports->formatFunctionOptions(),
            // Lists, not maps: Craft's editable table builds the select for a *new* row in
            // JavaScript, and its client-side reader wants `[{label, value}]`.
            'paramTypeOptions' => $this->asOptions(Parameter::types()),
            'columnFormatOptions' => $this->asOptions(Column::formats()),
            'frequencyOptions' => Schedule::frequencies(),
            'weekdayOptions' => Schedule::weekdays(),
            'deliveryOptions' => Delivery::options(),
            'elementTypeOptions' => $plugin->queries->elementTypeOptions(),
            'sourceOptions' => $plugin->queries->sourceOptions($spec->elementType),
            'columnOptions' => $plugin->queries->columnOptions($spec->elementType),
            'fsOptions' => $this->fsOptions(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_REPORTS);

        $request = $this->request;
        $reportId = $request->getBodyParam('reportId');

        $report = $reportId
            ? Plugin::getInstance()->reports->getReportById((int)$reportId)
            : new Report();

        if ($report === null) {
            throw new NotFoundHttpException('Report not found.');
        }

        $report->title = $request->getBodyParam('title', $report->title);
        $report->handle = (string)$request->getBodyParam('handle', $report->handle);
        $report->type = (string)$request->getBodyParam('type', $report->type);
        $report->description = $request->getBodyParam('description') ?: null;
        $report->template = $request->getBodyParam('template') ?: null;
        $report->formatFunction = $request->getBodyParam('formatFunction') ?: null;
        $report->format = (string)$request->getBodyParam('format', $report->format);
        $report->filenameFormat = $request->getBodyParam('filenameFormat') ?: null;
        $report->fsHandle = $request->getBodyParam('fsHandle') ?: null;
        $report->fsSubpath = $request->getBodyParam('fsSubpath') ?: null;
        $report->enabled = (bool)$request->getBodyParam('enabled', true);

        // A cleared number field posts `''`, and assigning that to a typed `?int` property is a
        // TypeError — the author empties a box and the save fatals with a stack trace instead of
        // a validation message.
        $report->retentionRuns = $this->intOrNull($request->getBodyParam('retentionRuns'));
        $report->retentionDays = $this->intOrNull($request->getBodyParam('retentionDays'));
        $report->batchSize = $this->intOrNull($request->getBodyParam('batchSize'));

        $report->setFormatOptions(FormatOptions::fromArray($request->getBodyParam('formatOptions') ?? []));
        $report->setSchedule(Schedule::fromArray($request->getBodyParam('schedule') ?? []));
        $report->setDelivery(Delivery::fromArray($request->getBodyParam('delivery') ?? []));

        // A save that never mentions the parameters must not erase them. Craft's editable table
        // always posts something when it is on screen, so `null` means the control was not
        // rendered — an API call, or a screen that does not show it.
        $params = $request->getBodyParam('params');

        if ($params !== null) {
            $report->setParams($this->cleanRows($params));
        }

        $querySpec = $request->getBodyParam('querySpec');

        if ($querySpec !== null) {
            $querySpec['columns'] = $this->cleanRows($querySpec['columns'] ?? []);
            $report->setQuerySpec(QuerySpec::fromArray($querySpec));
        }

        if (!Craft::$app->getElements()->saveElement($report)) {
            $this->setFailFlash(Craft::t('reportr', 'Couldn’t save the report.'));

            Craft::$app->getUrlManager()->setRouteParams(['report' => $report]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('reportr', 'Report saved.'));

        return $this->redirectToPostedUrl($report);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_REPORTS);

        $report = Plugin::getInstance()->reports->getReportById((int)$this->request->getRequiredBodyParam('reportId'));

        if ($report === null) {
            throw new NotFoundHttpException('Report not found.');
        }

        Craft::$app->getElements()->deleteElement($report);

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('reportr', 'Report deleted.'));
        }

        $this->setSuccessFlash(Craft::t('reportr', 'Report deleted.'));

        return $this->redirect('reportr/reports');
    }

    /**
     * The run screen.
     *
     * A GET because a report with parameters has to ask them first, and because "run this
     * report" is a link somebody wants to bookmark. The POST from that form does the work.
     */
    public function actionRun(?int $reportId = null): Response
    {
        $this->requirePermission(Plugin::PERMISSION_RUN_REPORTS);

        $reportId ??= (int)$this->request->getRequiredParam('reportId');
        $report = Plugin::getInstance()->reports->getReportById($reportId);

        if ($report === null) {
            throw new NotFoundHttpException('Report not found.');
        }

        $raw = $this->request->getBodyParam('params', $this->request->getQueryParam('params', []));
        $raw = is_array($raw) ? $raw : [];

        if (!$this->request->getIsPost()) {
            // No parameters and nothing to ask: run it straight away rather than showing a form
            // whose only control is the button.
            if (!$report->getHasParams()) {
                return $this->startRun($report, []);
            }

            return $this->renderTemplate('reportr/reports/_run', [
                'report' => $report,
                'values' => Plugin::getInstance()->reports->normalizeParams($report, $raw),
                'errors' => [],
            ]);
        }

        $reports = Plugin::getInstance()->reports;
        $normalized = $reports->normalizeParams($report, $raw);
        $errors = $reports->validateParams($report, $normalized);

        if ($errors !== []) {
            return $this->renderTemplate('reportr/reports/_run', [
                'report' => $report,
                'values' => $normalized,
                'errors' => $errors,
            ]);
        }

        return $this->startRun($report, $raw);
    }

    private function startRun(Report $report, array $rawParams): Response
    {
        $problem = $report->getBlockingProblem();

        if ($problem !== null) {
            $this->setFailFlash($problem);

            return $this->redirect((string)$report->getCpEditUrl());
        }

        $run = Plugin::getInstance()->runner->enqueue(
            $report,
            $rawParams,
            Run::INITIATOR_CP,
            Craft::$app->getUser()->getId(),
        );

        $this->setSuccessFlash(Craft::t('reportr', 'The report is building.'));

        return $this->redirect('reportr/runs/' . $run->id);
    }

    /**
     * Run the report with a row cap and show what came out, saving nothing.
     *
     * The loop this replaces is the reason to build it: save the template, run the report, wait
     * for the queue, download the file, notice the third column is empty, repeat.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_RUN_REPORTS);

        $reportId = (int)$this->request->getRequiredBodyParam('reportId');
        $report = Plugin::getInstance()->reports->getReportById($reportId);

        if ($report === null) {
            throw new NotFoundHttpException('Report not found.');
        }

        $raw = $this->request->getBodyParam('params', []);
        $result = Plugin::getInstance()->runner->preview($report, is_array($raw) ? $raw : []);

        return $this->asJson([
            'success' => $result->getSucceeded(),
            'error' => $result->error,
            'headings' => $result->headings,
            'rows' => $result->rows,
            'totalRows' => $result->totalRows,
            'truncated' => $result->truncated,
            'durationMs' => $result->durationMs,
        ]);
    }

    /**
     * The source and column pickers for a chosen element type.
     *
     * Fetched rather than rendered up front, because the lists depend on the element type select
     * and re-rendering the whole screen to change one dropdown is a poor trade.
     */
    public function actionQueryOptions(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_REPORTS);

        $elementType = (string)$this->request->getRequiredParam('elementType');
        $queries = Plugin::getInstance()->queries;

        return $this->asJson([
            'sources' => $queries->sourceOptions($elementType),
            'columns' => $queries->columnOptions($elementType),
        ]);
    }

    // Plumbing
    // -------------------------------------------------------------------------

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }

    /**
     * Drop the blank trailing row Craft's editable table always posts.
     *
     * It posts one whether or not anybody typed in it, so without this every save adds an empty
     * parameter — which then fails validation, or worse, does not.
     */
    private function cleanRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $cleaned = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $hasContent = false;

            foreach ($row as $value) {
                if (is_array($value) ? $value !== [] : trim((string)$value) !== '') {
                    $hasContent = true;

                    break;
                }
            }

            if ($hasContent) {
                $cleaned[] = $row;
            }
        }

        return $cleaned;
    }

    /**
     * A map turned into the list of `{label, value}` pairs Craft's editable table expects.
     *
     * @param array<string, string> $map
     * @return array<int, array{label: string, value: string}>
     */
    private function asOptions(array $map): array
    {
        $options = [];

        foreach ($map as $value => $label) {
            $options[] = ['label' => $label, 'value' => (string)$value];
        }

        return $options;
    }

    /** @return array<int, array{label: string, value: string}> */
    private function fsOptions(): array
    {
        $options = [['label' => Craft::t('reportr', 'Local storage folder'), 'value' => '']];

        foreach (Craft::$app->getFs()->getAllFilesystems() as $fs) {
            $options[] = ['label' => $fs->name, 'value' => $fs->handle];
        }

        return $options;
    }
}
