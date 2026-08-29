<?php

declare(strict_types=1);

namespace justinholtweb\reportr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

/**
 * The runs index, one run's detail page, and downloads.
 */
class RunsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW_RUNS);

        return true;
    }

    public function actionIndex(): Response
    {
        $reportId = $this->request->getQueryParam('reportId');

        return $this->renderTemplate('reportr/runs/_index', [
            // Passed as the element itself, because the index wants the *source key* — which is
            // built from the report's UID — rather than its ID.
            'report' => $reportId !== null
                ? Plugin::getInstance()->reports->getReportById((int)$reportId)
                : null,
        ]);
    }

    public function actionDetail(?int $runId = null): Response
    {
        $runId ??= (int)$this->request->getRequiredParam('runId');
        $run = $this->findRun($runId);

        return $this->renderTemplate('reportr/runs/_detail', [
            'run' => $run,
            'report' => $run->getReport(),
            'history' => $run->getReport()?->getRuns(20) ?? [],
            'canDownload' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_DOWNLOAD_RUNS),
            'canDelete' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_DELETE_RUNS),
        ]);
    }

    /**
     * Send the file.
     *
     * Streamed, never read into a string first: a 400 MB export served with `file_get_contents()`
     * needs 400 MB of PHP memory it does not have, and the failure looks like a broken download
     * rather than a memory limit.
     */
    public function actionDownload(?int $runId = null): Response
    {
        $this->requirePermission(Plugin::PERMISSION_DOWNLOAD_RUNS);

        $runId ??= (int)$this->request->getRequiredParam('runId');
        $run = $this->findRun($runId);

        if (!$run->getIsFinished()) {
            throw new NotFoundHttpException('That report has not finished building.');
        }

        $stream = Plugin::getInstance()->storage->stream($run);

        if ($stream === null) {
            // Says which filesystem it looked on. "Unavailable (File Missing)" with nothing else
            // is what the plugin this replaces told people, and it left them nowhere to start.
            throw new NotFoundHttpException(Craft::t('reportr', 'The report file is missing from {where}.', [
                'where' => $run->fsHandle
                    ? Craft::t('reportr', 'the “{fs}” filesystem', ['fs' => $run->fsHandle])
                    : Craft::t('reportr', 'local storage'),
            ]));
        }

        $formats = Plugin::getInstance()->formats;

        return $this->response->sendStreamAsFile($stream, (string)$run->filename, [
            'mimeType' => $formats->mimeType((string)$run->format),
            'fileSize' => $run->fileSize,
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE_RUNS);

        $run = $this->findRun((int)$this->request->getRequiredBodyParam('runId'));
        $hard = (bool)$this->request->getBodyParam('hardDelete', false);

        if (!Craft::$app->getElements()->deleteElement($run, $hard)) {
            throw new ServerErrorHttpException('Could not delete the run.');
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('reportr', 'Run deleted.'));
        }

        $this->setSuccessFlash(Craft::t('reportr', 'Run deleted.'));

        return $this->redirect('reportr/runs');
    }

    /**
     * How far along a running build is, for the detail page to poll.
     *
     * Cheap on purpose — one element query and no rendering — because a page watching a
     * forty-minute export will ask a few hundred times.
     */
    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();

        $run = $this->findRun((int)$this->request->getRequiredParam('runId'));

        return $this->asJson([
            'status' => $run->runStatus,
            'label' => $run->getStatusLabel(),
            'totalRows' => $run->totalRows,
            'finished' => in_array($run->runStatus, [Run::STATUS_FINISHED, Run::STATUS_ERROR], true),
            'message' => $run->statusMessage,
        ]);
    }

    private function findRun(int $id): Run
    {
        /** @var Run|null $run */
        $run = Run::find()->id($id)->status(null)->one();

        if ($run === null) {
            throw new NotFoundHttpException('Run not found.');
        }

        return $run;
    }
}
