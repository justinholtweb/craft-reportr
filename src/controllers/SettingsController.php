<?php

declare(strict_types=1);

namespace justinholtweb\reportr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\reportr\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('reportr/settings/_index', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'fsOptions' => $this->fsOptions(),
            'storagePath' => $plugin->getSettings()->getStoragePath(),
            'functionNames' => $plugin->reports->formatFunctionNames(),
            'formats' => $plugin->formats->options(),
            'unsupported' => array_values(array_filter(
                array_keys($plugin->formats->all()),
                static fn(string $format) => !$plugin->formats->isSupported($format),
            )),
            'importAvailable' => $plugin->importer->isAvailable(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        // No `settings[…]` prefix on the field names in the template: Craft namespaces plugin
        // settings HTML itself for `settingsHtml()`, but this is a screen of our own, so the
        // params arrive exactly as they are named. Reading them explicitly keeps the two
        // arrangements from being confused for each other.
        $settings->setAttributes($this->request->getBodyParam('settings', []), false);

        if (!$settings->validate()) {
            $this->setFailFlash(Craft::t('reportr', 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams(['settings' => $settings]);

            return null;
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('reportr', 'Couldn’t save settings.'));

            return null;
        }

        $this->setSuccessFlash(Craft::t('reportr', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /** Recompute every stored next-run time — after a timezone change, or a restored database. */
    public function actionRefreshSchedules(): Response
    {
        $this->requirePostRequest();

        $count = Plugin::getInstance()->schedules->refreshAll();

        $this->setSuccessFlash(Craft::t('reportr', '{count} schedules recalculated.', ['count' => $count]));

        return $this->redirect('reportr/settings');
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
