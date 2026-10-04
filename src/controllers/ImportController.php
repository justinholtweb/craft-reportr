<?php

declare(strict_types=1);

namespace justinholtweb\reportr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\reportr\Plugin;
use yii\web\Response;

/**
 * The Lab Reports import screen.
 */
class ImportController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Admin, but not admin *changes*: production sites switch those off, and the importer, the
        // schedule refresh and reading the settings are not project config.
        $this->requireAdmin(false);

        return true;
    }

    public function actionIndex(): Response
    {
        $importer = Plugin::getInstance()->importer;

        return $this->renderTemplate('reportr/import/_index', [
            'available' => $importer->isAvailable(),
            'counts' => $importer->counts(),
            'legacyFolder' => $importer->legacyStorageFolder(),
            'summary' => null,
        ]);
    }

    public function actionRun(): Response
    {
        $this->requirePostRequest();

        $importer = Plugin::getInstance()->importer;

        // The dry run is the default and the button that skips it is the one that says "Import".
        // An import that has already happened cannot be un-happened, and this one touches a live
        // site's report list.
        $dryRun = (bool)$this->request->getBodyParam('dryRun', true);

        $summary = $importer->import(
            $dryRun,
            (bool)$this->request->getBodyParam('includeRuns', true),
            (bool)$this->request->getBodyParam('copyFiles', true),
        );

        if (!$dryRun) {
            $this->setSuccessFlash(Craft::t('reportr', '{count} records imported.', [
                'count' => $summary->getTotalImported(),
            ]));
        }

        return $this->renderTemplate('reportr/import/_index', [
            'available' => $importer->isAvailable(),
            'counts' => $importer->counts(),
            'legacyFolder' => $importer->legacyStorageFolder(),
            'summary' => $summary,
        ]);
    }
}
