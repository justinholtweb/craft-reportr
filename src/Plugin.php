<?php

declare(strict_types=1);

namespace justinholtweb\reportr;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\reportr\elements\Report;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\models\Settings;
use justinholtweb\reportr\services\Formats;
use justinholtweb\reportr\services\Importer;
use justinholtweb\reportr\services\Notifications;
use justinholtweb\reportr\services\QueryBuilder;
use justinholtweb\reportr\services\Reports;
use justinholtweb\reportr\services\Runner;
use justinholtweb\reportr\services\Schedules;
use justinholtweb\reportr\services\Storage;
use justinholtweb\reportr\twig\Extension;
use justinholtweb\reportr\twig\ReportrVariable;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use yii\base\Event;

/**
 * Reportr — content and data reports for Craft CMS.
 *
 * A replacement for Masuga's Lab Reports, which stopped taking new features. It keeps that
 * plugin's template contract exactly — same variable name, same `build()` signature, same
 * formatting-function config — so existing report templates run unchanged, and then does the
 * things its issue tracker asked for and never got: run-time parameters (its issue #1, and the
 * top item on its own roadmap), export formats beyond CSV (its second roadmap item), reports
 * stored on a Craft filesystem rather than local disk (issue #8), a raised queue TTR and
 * streaming writes (issues #6 and #7), deletable configurations (issue #5), and a
 * point-and-click report type for the people who were never going to write Twig.
 *
 * @property-read Reports $reports
 * @property-read Runner $runner
 * @property-read Storage $storage
 * @property-read Formats $formats
 * @property-read Schedules $schedules
 * @property-read Notifications $notifications
 * @property-read Importer $importer
 * @property-read QueryBuilder $queries
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_VIEW_REPORTS = 'reportr:viewReports';
    public const PERMISSION_MANAGE_REPORTS = 'reportr:manageReports';
    public const PERMISSION_RUN_REPORTS = 'reportr:runReports';
    public const PERMISSION_VIEW_RUNS = 'reportr:viewRuns';
    public const PERMISSION_DOWNLOAD_RUNS = 'reportr:downloadRuns';
    public const PERMISSION_DELETE_RUNS = 'reportr:deleteRuns';

    public const LOG_CATEGORY = 'reportr';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    /** The defaults from `src/config.php`, merged with the site's `config/reportr.php`. */
    private ?array $config = null;

    public static function config(): array
    {
        return [
            'components' => [
                'reports' => Reports::class,
                'runner' => Runner::class,
                'storage' => Storage::class,
                'formats' => Formats::class,
                'schedules' => Schedules::class,
                'notifications' => Notifications::class,
                'importer' => Importer::class,
                'queries' => QueryBuilder::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogTarget();
        $this->registerElementTypes();
        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerGarbageCollection();
        $this->registerScheduleFallback();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Reportr's settings are a screen inside its own section, not a pane.
     *
     * Never a redirect to `settings/plugins/reportr` — that is the URL Craft renders
     * `settingsHtml()` at, so redirecting there is an infinite loop rather than an override.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('reportr/settings'));
    }

    /**
     * `storage/logs/reportr.log`, which the settings screen and the README both promise.
     *
     * Everything is logged under the `reportr` category already; without a target of its own it
     * lands in web.log, console.log or queue.log depending on who happened to run the build.
     */
    private function registerLogTarget(): void
    {
        $dispatcher = Craft::getLogger()->dispatcher;

        // A web request and a queue job both boot the plugin; the dispatcher is shared.
        if (isset($dispatcher->targets['reportr'])) {
            return;
        }

        $dispatcher->targets['reportr'] = new MonologTarget([
            'name' => 'reportr',
            'categories' => ['reportr'],
            'level' => $this->getSettings()->debug ? LogLevel::DEBUG : LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => true,
            'formatter' => new LineFormatter(
                format: "%datetime% [%level_name%] %message%\n",
                dateFormat: 'Y-m-d H:i:s',
                allowInlineLineBreaks: true,
            ),
        ]);
    }

    /** The same screen, which renders read-only when admin changes are switched off. */
    public function getReadOnlySettingsResponse(): mixed
    {
        return $this->getSettingsResponse();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('reportr', 'Reportr');

        $user = Craft::$app->getUser();

        if ($user->checkPermission(self::PERMISSION_VIEW_REPORTS)) {
            $item['subnav']['reports'] = [
                'label' => Craft::t('reportr', 'Reports'),
                'url' => 'reportr/reports',
            ];
        }

        if ($user->checkPermission(self::PERMISSION_VIEW_RUNS)) {
            $item['subnav']['runs'] = [
                'label' => Craft::t('reportr', 'Runs'),
                'url' => 'reportr/runs',
            ];
        }

        if ($user->getIsAdmin()) {
            // Only offered while there is something to import. A permanent menu item pointing at
            // an empty screen is noise on every site that never used the other plugin.
            try {
                if ($this->importer->isAvailable()) {
                    $item['subnav']['import'] = [
                        'label' => Craft::t('reportr', 'Import'),
                        'url' => 'reportr/import',
                    ];
                }
            } catch (\Throwable) {
                // The navigation is built on every CP request, including ones Craft serves while
                // a migration is part-applied. Nothing here is worth a white screen.
            }

            $item['subnav']['settings'] = [
                'label' => Craft::t('reportr', 'Settings'),
                'url' => 'reportr/settings',
            ];
        }

        return $item;
    }

    // Config file
    // -------------------------------------------------------------------------

    /** The plugin's config file, merged over its own defaults. */
    public function getConfig(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $defaults = require $this->getBasePath() . DIRECTORY_SEPARATOR . 'config.php';

        return $this->config = array_merge($defaults, Craft::$app->getConfig()->getConfigFromFile('reportr'));
    }

    /** Lab Reports' accessor name, kept so that a copied snippet keeps working. */
    public function getConfigItem(string $key): mixed
    {
        return $this->getConfig()[$key] ?? null;
    }

    // Registration
    // -------------------------------------------------------------------------

    private function registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, static function(RegisterComponentTypesEvent $event) {
            $event->types[] = Report::class;
            $event->types[] = Run::class;
        });
    }

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, static function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'reportr' => 'reportr/reports/index',
                'reportr/reports' => 'reportr/reports/index',
                'reportr/reports/new' => 'reportr/reports/edit',
                'reportr/reports/<reportId:\d+>' => 'reportr/reports/edit',
                'reportr/reports/<reportId:\d+>/run' => 'reportr/reports/run',
                'reportr/runs' => 'reportr/runs/index',
                'reportr/runs/<runId:\d+>' => 'reportr/runs/detail',
                'reportr/runs/<runId:\d+>/download' => 'reportr/runs/download',
                'reportr/import' => 'reportr/import/index',
                'reportr/settings' => 'reportr/settings/index',
            ];
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('reportr', 'Reportr'),
                'permissions' => [
                    self::PERMISSION_VIEW_REPORTS => [
                        'label' => Craft::t('reportr', 'View report configurations'),
                        'nested' => [
                            self::PERMISSION_MANAGE_REPORTS => ['label' => Craft::t('reportr', 'Create, edit and delete reports')],
                            self::PERMISSION_RUN_REPORTS => ['label' => Craft::t('reportr', 'Run reports')],
                        ],
                    ],
                    // Not nested under the reports permission, and the distinction is the point:
                    // a report's *output* is the data. Somebody who may see that the "Members
                    // export" exists is not thereby somebody who may download every member's
                    // email address.
                    self::PERMISSION_VIEW_RUNS => [
                        'label' => Craft::t('reportr', 'View runs'),
                        'nested' => [
                            self::PERMISSION_DOWNLOAD_RUNS => ['label' => Craft::t('reportr', 'Download report files')],
                            self::PERMISSION_DELETE_RUNS => ['label' => Craft::t('reportr', 'Delete runs')],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $event) {
            $event->sender->set('reportr', ReportrVariable::class);
        });

        Craft::$app->getView()->registerTwigExtension(new Extension());
    }

    /**
     * Two housekeeping jobs on Craft's own garbage collection.
     *
     * The stalled-run sweep is the important one and is easy to miss: a queue job killed by the
     * TTR, an out-of-memory fatal or a restarted container leaves a run marked "Running" with
     * nothing left to finish it. Without this, the index shows a spinner for ever and the only
     * fix is a database edit.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            try {
                $this->runner->failStalledRuns();
            } catch (\Throwable $e) {
                Craft::error('Reportr’s stalled-run sweep failed: ' . $e->getMessage(), self::LOG_CATEGORY);
            }

            try {
                foreach ($this->reports->getAllReports() as $report) {
                    $this->runner->applyRetention($report);
                }
            } catch (\Throwable $e) {
                Craft::error('Reportr’s retention sweep failed: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    /**
     * The control-panel schedule fallback, modelled on Craft's `runQueueAutomatically`.
     *
     * Hooked to `EVENT_AFTER_REQUEST`, which Yii fires *before* the response is sent — so this
     * does sit in somebody's page load, and the work is kept to what that can afford: one cache
     * read on all but one request a minute, and even then only queueing jobs rather than building
     * anything. Control-panel requests only; a front-end page load is a visitor waiting.
     */
    private function registerScheduleFallback(): void
    {
        if (!Craft::$app instanceof WebApplication) {
            return;
        }

        Event::on(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST, function() {
            if (!Craft::$app->getRequest()->getIsCpRequest() || Craft::$app->getRequest()->getIsAjax()) {
                return;
            }

            $this->schedules->runAutomatically();
        });
    }
}
