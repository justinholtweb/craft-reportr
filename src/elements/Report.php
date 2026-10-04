<?php

declare(strict_types=1);

namespace justinholtweb\reportr\elements;

use Craft;
use craft\base\Element;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\reportr\elements\db\ReportQuery;
use justinholtweb\reportr\helpers\Access;
use justinholtweb\reportr\models\Delivery;
use justinholtweb\reportr\models\FormatOptions;
use justinholtweb\reportr\models\Parameter;
use justinholtweb\reportr\models\QuerySpec;
use justinholtweb\reportr\models\Schedule;
use justinholtweb\reportr\Plugin;
use justinholtweb\reportr\records\ReportRecord;

/**
 * A report definition — the recipe, not the meal.
 *
 * ## Why the definition is an element
 *
 * It could have been a settings row, and in the plugin this replaces it very nearly was. Being an
 * element buys the things a report configuration turns out to need and nobody wants to build
 * twice: an index with sources and sortable columns, search, the trash (so deleting the report
 * that finance depends on is survivable), and — the one that matters most — a stable ID that a
 * generated run can point at.
 *
 * ## Three types, one output
 *
 * - **basic** — a Twig template that builds an array of rows and calls `report.build(rows)`.
 *   Everything is in memory at once, which is fine up to a few thousand rows and is the shape
 *   most reports actually are.
 * - **advanced** — a Twig template that hands over column headings and an *unexecuted* element
 *   query, plus the name of a PHP function from `config/reportr.php` that turns one element into
 *   one row. The runner walks the query in batches, so the report's size is bounded by disk
 *   rather than by memory.
 * - **query** — no template at all. An element query built in the control panel, with columns
 *   picked from a list. The type that exists so that "export the members who joined last month"
 *   is not a deployment.
 *
 * The first two are deliberately call-compatible with Lab Reports, down to the variable name
 * (`report`) and the method signatures on it, so a template imported from that plugin runs
 * unchanged. That compatibility is a feature with a cost — `build()` living on the *run* rather
 * than on the report is not where it would go in a fresh design — and it is worth the cost,
 * because the alternative is asking somebody to rewrite forty templates before they can try this.
 *
 * ## Handles
 *
 * Reports have handles as well as IDs. Lab Reports' console command took `--reportId=43248`,
 * which means every cron entry in every deployment carries a number that means nothing to a
 * reader and is different on staging. `--report=monthly-orders` survives a database refresh.
 *
 * @property-read Parameter[] $params
 * @property-read Schedule $schedule
 * @property-read Delivery $delivery
 * @property-read FormatOptions $formatOptions
 * @property-read QuerySpec $querySpec
 */
class Report extends Element
{
    public const TYPE_BASIC = 'basic';
    public const TYPE_ADVANCED = 'advanced';
    public const TYPE_QUERY = 'query';

    public const TYPES = [self::TYPE_BASIC, self::TYPE_ADVANCED, self::TYPE_QUERY];

    public string $handle = '';
    public string $type = self::TYPE_BASIC;
    public ?string $description = null;
    public ?string $template = null;
    public ?string $formatFunction = null;
    public string $format = 'csv';
    public ?string $filenameFormat = null;
    public ?string $fsHandle = null;
    public ?string $fsSubpath = null;
    public ?DateTime $nextRunAt = null;
    public ?DateTime $lastRunAt = null;
    public ?int $retentionRuns = null;
    public ?int $retentionDays = null;
    public ?int $batchSize = null;
    public int $runCount = 0;
    public ?int $sortOrder = null;

    /** The Lab Reports row this was imported from, so a re-run of the import is a no-op. */
    public ?int $legacyId = null;

    /** @var Parameter[]|null */
    private ?array $_params = null;

    private ?Schedule $_schedule = null;
    private ?Delivery $_delivery = null;
    private ?FormatOptions $_formatOptions = null;
    private ?QuerySpec $_querySpec = null;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('reportr', 'Report');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('reportr', 'report');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('reportr', 'Reports');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('reportr', 'reports');
    }

    public static function refHandle(): ?string
    {
        return 'report';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * A report is one thing, not one thing per site.
     *
     * Which site's *content* a report reads is a question its query or its parameters answer, and
     * that is a different question from which sites the configuration exists in. Lab Reports made
     * its elements localised and then had to set `siteId` by hand in the runner to stop Craft
     * throwing `UnsupportedSiteException`, which is the shape of a decision fighting itself.
     */
    public static function isLocalized(): bool
    {
        return false;
    }

    public static function find(): ReportQuery
    {
        return new ReportQuery(static::class);
    }

    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['nextRunAt', 'lastRunAt']);
    }

    public function getUiLabel(): string
    {
        return $this->title ?: Craft::t('reportr', 'Untitled report');
    }

    public function __toString(): string
    {
        return $this->getUiLabel();
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl('reportr/reports/' . $this->getCanonicalId());
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('reportr/reports');
    }

    /** Where "Run" goes. A GET, because a report with parameters has to ask them first. */
    public function getRunUrl(): string
    {
        return UrlHelper::cpUrl('reportr/reports/' . $this->getCanonicalId() . '/run');
    }

    // Typed sub-models
    // -------------------------------------------------------------------------

    /**
     * Every one of these setters has to accept a JSON string.
     *
     * Craft hands the raw database row to the element's constructor, so a stored column with no
     * property and no setter is `UnknownPropertyException` thrown from `createElement()` — and
     * only when the element is read *from the database*, never when it is read back from the
     * identity map straight after a save. The bug hides from exactly the test most likely to be
     * written for it.
     *
     * @return Parameter[]
     */
    public function getParams(): array
    {
        return $this->_params ??= [];
    }

    public function setParams(mixed $value): void
    {
        $config = $this->decode($value);
        $params = [];

        foreach ($config as $item) {
            if (!is_array($item)) {
                continue;
            }

            $param = Parameter::fromArray($item);

            if ($param->name !== '') {
                // Later definitions of the same name win, rather than both surviving to confuse
                // the run form with two controls posting to one key.
                $params[$param->name] = $param;
            }
        }

        $this->_params = array_values($params);
    }

    public function getParam(string $name): ?Parameter
    {
        foreach ($this->getParams() as $param) {
            if ($param->name === $name) {
                return $param;
            }
        }

        return null;
    }

    public function getHasParams(): bool
    {
        return $this->getParams() !== [];
    }

    public function getSchedule(): Schedule
    {
        return $this->_schedule ??= new Schedule();
    }

    public function setSchedule(mixed $value): void
    {
        $this->_schedule = $value instanceof Schedule ? $value : Schedule::fromArray($this->decode($value));
    }

    public function getDelivery(): Delivery
    {
        return $this->_delivery ??= new Delivery();
    }

    public function setDelivery(mixed $value): void
    {
        $this->_delivery = $value instanceof Delivery ? $value : Delivery::fromArray($this->decode($value));
    }

    public function getFormatOptions(): FormatOptions
    {
        return $this->_formatOptions ??= new FormatOptions();
    }

    public function setFormatOptions(mixed $value): void
    {
        $this->_formatOptions = $value instanceof FormatOptions
            ? $value
            : FormatOptions::fromArray($this->decode($value));
    }

    public function getQuerySpec(): QuerySpec
    {
        return $this->_querySpec ??= new QuerySpec();
    }

    public function setQuerySpec(mixed $value): void
    {
        $this->_querySpec = $value instanceof QuerySpec ? $value : QuerySpec::fromArray($this->decode($value));
    }

    private function decode(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value !== '' ? Json::decodeIfJson($value) : [];
        }

        return is_array($value) ? $value : [];
    }

    // Behaviour
    // -------------------------------------------------------------------------

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_ADVANCED => Craft::t('reportr', 'Advanced'),
            self::TYPE_QUERY => Craft::t('reportr', 'Query'),
            default => Craft::t('reportr', 'Basic'),
        };
    }

    public function getUsesTemplate(): bool
    {
        return $this->type === self::TYPE_BASIC || $this->type === self::TYPE_ADVANCED;
    }

    public function getEffectiveBatchSize(): int
    {
        $size = $this->batchSize ?: Plugin::getInstance()->getSettings()->batchSize;

        return max(1, min(10000, $size));
    }

    /** @return Run[] */
    public function getRuns(int $limit = 10): array
    {
        if ($this->id === null) {
            return [];
        }

        /** @var \justinholtweb\reportr\elements\db\RunQuery $query */
        $query = Run::find();

        // `status(null)` explicitly: without it the query carries Craft's default of "enabled",
        // which happens to match every run today and would silently start hiding them the moment
        // anything disabled one.
        return $query->reportId($this->getCanonicalId())
            ->status(null)
            ->orderBy(['reportr_runs.dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function getLastRun(): ?Run
    {
        return $this->getRuns(1)[0] ?? null;
    }

    /**
     * Why this report cannot run, in a sentence, or null if it can.
     *
     * A single place that answers the question, so the index, the edit screen, the run button and
     * the console command cannot disagree about whether a report is usable — and so nobody
     * schedules one that was never going to work.
     */
    public function getBlockingProblem(): ?string
    {
        if ($this->getUsesTemplate() && !$this->template) {
            return Craft::t('reportr', 'No template is set.');
        }

        if ($this->type === self::TYPE_ADVANCED && !$this->formatFunction) {
            return Craft::t('reportr', 'No formatting function is set.');
        }

        if ($this->type === self::TYPE_ADVANCED
            && Plugin::getInstance()->reports->formatFunction($this->formatFunction) === null) {
            return Craft::t('reportr', 'The formatting function “{name}” is not defined in config/reportr.php.', [
                'name' => $this->formatFunction,
            ]);
        }

        if ($this->type === self::TYPE_QUERY && !$this->getQuerySpec()->getIsUsable()) {
            return Craft::t('reportr', 'No columns have been chosen.');
        }

        if (!Plugin::getInstance()->formats->isSupported($this->format)) {
            return Craft::t('reportr', 'The {format} format is not available on this server.', [
                'format' => strtoupper($this->format),
            ]);
        }

        return null;
    }

    // Saving
    // -------------------------------------------------------------------------

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['handle'], 'required'];
        $rules[] = [['handle'], 'string', 'max' => 64];
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-z][a-z0-9\-]*$/', 'message' => Craft::t('reportr', 'Handles may only contain lowercase letters, numbers and hyphens, and must start with a letter.')];
        $rules[] = [['handle'], 'validateHandleIsUnique'];
        $rules[] = [['type'], 'in', 'range' => self::TYPES];
        $rules[] = [['format'], 'validateFormat'];
        $rules[] = [['retentionRuns', 'retentionDays', 'batchSize'], 'integer', 'min' => 0];
        $rules[] = [['template'], 'string', 'max' => 255];
        $rules[] = [['formatFunction'], 'string', 'max' => 100];
        $rules[] = [['template'], 'required', 'when' => fn(self $report) => $report->getUsesTemplate()];
        $rules[] = [['formatFunction'], 'required', 'when' => fn(self $report) => $report->type === self::TYPE_ADVANCED];
        $rules[] = [['querySpec'], 'validateAccess', 'skipOnEmpty' => false];
        $rules[] = [['params'], 'validateParamNames', 'skipOnEmpty' => false];
        $rules[] = [['fsSubpath'], 'match', 'not' => true, 'pattern' => '#(^|[/\\\\])\.\.([/\\\\]|$)#', 'message' => Craft::t('reportr', 'The folder can’t step outside the filesystem with “..”.')];

        return $rules;
    }

    /**
     * Someone who isn't an admin may only build a report over content they can view, and may not
     * change what the report runs or where its output goes. See helpers\Access.
     *
     * Admin-only, whatever the type: the type itself, the template and formatting function (code
     * that runs), a `twig:` column, the filesystem, folder and filename (where a file lands, and
     * so what it can overwrite). Changing who is emailed the output takes permission to download
     * it, since that is what an email with the file attached is. A column that reaches a user —
     * `author.email` — takes permission to view users.
     *
     * Only what changed is checked: editing the title of a report an admin built over users does
     * not need the users permission, and a `twig:` column an admin wrote can be kept or removed.
     * Console runs and code with nobody signed in are trusted.
     */
    public function validateAccess(string $attribute): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || $user->admin) {
            return;
        }

        $stored = $this->id ? ReportRecord::findOne($this->id) : null;
        $adminOnly = Craft::t('reportr', 'Only an admin can change this.');

        $changed = static fn(?string $now, ?string $was): bool => ($now ?: null) !== ($was ?: null);

        if ($stored === null ? $this->type !== self::TYPE_QUERY : $changed($this->type, $stored->type)) {
            $this->addError('type', Craft::t('reportr', 'Only an admin can create or change a report that runs a template.'));
        }

        foreach (['template', 'formatFunction', 'fsHandle', 'fsSubpath', 'filenameFormat'] as $name) {
            if ($changed($this->$name, $stored?->$name)) {
                $this->addError($name, $adminOnly);
            }
        }

        $delivery = $this->getDelivery()->toArray();
        $storedDelivery = Delivery::fromArray($stored ? $this->decode($stored->delivery) : null)->toArray();
        $who = static fn(array $d): array => [$d['when'] === Delivery::WHEN_NEVER ? [] : $d['recipients'], $d['recipients'] === [] ? null : $d['attach']];

        if ($who($delivery) !== $who($storedDelivery) && !$user->can(Plugin::PERMISSION_DOWNLOAD_RUNS)) {
            $this->addError('delivery', Craft::t('reportr', 'Choosing who is sent a report’s output takes permission to download it.'));
        }

        if ($this->type !== self::TYPE_QUERY) {
            return;
        }

        $spec = $this->getQuerySpec();
        $storedSpec = $stored ? QuerySpec::fromArray($this->decode($stored->querySpec)) : null;
        $sourceChanged = $storedSpec === null
            || $storedSpec->elementType !== $spec->elementType
            || $storedSpec->source !== $spec->source
            || $stored->type !== self::TYPE_QUERY;

        if ($sourceChanged && ($reason = Access::whyNotSource($spec, $user)) !== null) {
            $this->addError($attribute, $reason);
        }

        $added = array_diff(Access::twigColumns($spec), $storedSpec ? Access::twigColumns($storedSpec) : []);

        if ($added !== []) {
            $this->addError($attribute, Craft::t('reportr', 'Only an admin can add or change a Twig column ({column}).', [
                'column' => mb_strimwidth((string)reset($added), 0, 60, '…'),
            ]));
        }

        if (!$user->can('viewUsers')) {
            $reachUsers = array_diff(Access::userColumns($spec), $storedSpec ? Access::userColumns($storedSpec) : []);

            if ($reachUsers !== []) {
                $this->addError($attribute, Craft::t('reportr', 'You can’t view users, so you can’t add a column that reads them ({column}).', [
                    'column' => mb_strimwidth((string)reset($reachUsers), 0, 60, '…'),
                ]));
            }
        }
    }

    /**
     * Parameter names that are element-query machinery rather than filters — `where`, `orderBy`,
     * `editable` — are refused for everyone. They would not reach the query anyway (only
     * QueryBuilder::QUERY_PARAMS do), and a parameter that silently does nothing misleads.
     */
    public function validateParamNames(string $attribute): void
    {
        foreach ($this->getParams() as $param) {
            if (Parameter::isReservedName($param->name)) {
                $this->addError($attribute, Craft::t('reportr', '“{name}” is reserved and can’t be a parameter name.', ['name' => $param->name]));
            }
        }
    }

    /**
     * Uniqueness checked against an element query, not a unique validator.
     *
     * A `UniqueValidator` over the sub-table sees trashed rows, so a report that was deleted six
     * months ago holds its handle forever and the author is told "already taken" by something
     * they cannot see anywhere in the control panel. The column value itself is parked on delete
     * and reclaimed on restore, further down.
     */
    public function validateHandleIsUnique(): void
    {
        if ($this->handle === '') {
            return;
        }

        /** @var ReportQuery $query */
        $query = self::find();
        $query->handle($this->handle)->status(null)->siteId('*');

        if ($this->id !== null) {
            $query->andWhere(['not', ['elements.id' => $this->getCanonicalId()]]);
        }

        if ($query->exists()) {
            $this->addError('handle', Craft::t('reportr', 'That handle is already in use.'));
        }
    }

    public function validateFormat(): void
    {
        if (!Plugin::getInstance()->formats->has($this->format)) {
            $this->addError('format', Craft::t('reportr', 'Unknown export format.'));
        }
    }

    /**
     * A copy made by Craft's Duplicate action starts life as a new report, not a twin.
     *
     * Craft validates the copy before saving it, so the original's handle fails uniqueness and the
     * action fails outright. The import marker, the counters and — because a copy of a scheduled
     * report would otherwise start mailing its recipients twice — the enabled state are reset too.
     */
    public function beforeValidate(): bool
    {
        if ($this->duplicateOf !== null && !$this->id) {
            $this->handle = $this->generateHandle($this->handle ?: (string)$this->title);
            $this->legacyId = null;
            $this->runCount = 0;
            $this->lastRunAt = null;
            $this->enabled = false;
        }

        return parent::beforeValidate();
    }

    public function beforeSave(bool $isNew): bool
    {
        if ($this->handle === '' && $this->title) {
            $this->handle = $this->generateHandle($this->title);
        }

        // Recomputed on every save rather than only when the schedule changes: the frequency, the
        // time and the report's enabled state all move it, and a stored next-run that disagrees
        // with the rule beside it is the worst of both designs.
        $this->nextRunAt = $this->enabled
            ? $this->getSchedule()->nextOccurrence()
            : null;

        return parent::beforeSave($isNew);
    }

    private function generateHandle(string $from): string
    {
        $base = StringHelper::slugify($from) ?: 'report';
        $handle = mb_substr($base, 0, 64);
        $suffix = 1;

        while (self::find()->handle($handle)->status(null)->exists()) {
            $suffix++;
            $handle = mb_substr($base, 0, 60) . '-' . $suffix;
        }

        return $handle;
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = $isNew ? new ReportRecord() : ReportRecord::findOne($this->id);

            if ($record === null) {
                $record = new ReportRecord();
                $isNew = true;
            }

            if ($isNew) {
                $record->id = $this->id;
            }

            $record->handle = $this->handle;
            $record->type = $this->type;
            $record->description = $this->description;
            $record->template = $this->template;
            $record->formatFunction = $this->formatFunction;
            $record->format = $this->format;
            $record->formatOptions = Json::encode($this->getFormatOptions()->toArray());
            $record->params = Json::encode(array_map(
                static fn(Parameter $param) => $param->toArray(),
                $this->getParams(),
            ));
            $record->querySpec = Json::encode($this->getQuerySpec()->toArray());
            $record->filenameFormat = $this->filenameFormat;
            $record->fsHandle = $this->fsHandle;
            $record->fsSubpath = $this->fsSubpath;
            $record->schedule = Json::encode($this->getSchedule()->toArray());
            $record->delivery = Json::encode($this->getDelivery()->toArray());
            $record->nextRunAt = Db::prepareDateForDb($this->nextRunAt);
            $record->lastRunAt = Db::prepareDateForDb($this->lastRunAt);
            $record->retentionRuns = $this->retentionRuns;
            $record->retentionDays = $this->retentionDays;
            $record->batchSize = $this->batchSize;
            $record->runCount = $this->runCount;
            $record->sortOrder = $this->sortOrder;
            $record->legacyId = $this->legacyId;
            $record->save(false);
        }

        parent::afterSave($isNew);
    }

    /**
     * Park the handle so a trashed report does not hold a name nobody can see.
     *
     * The suffix carries the ID, so two reports called `orders` deleted a year apart do not
     * collide with each other in the trash either.
     */
    public function afterDelete(): void
    {
        if (!$this->hardDelete) {
            $parked = mb_substr($this->handle, 0, 40) . '--trashed-' . $this->id;

            Craft::$app->getDb()->createCommand()
                ->update(\justinholtweb\reportr\records\Table::REPORTS, [
                    'handle' => $parked,
                    'nextRunAt' => null,
                ], ['id' => $this->id])
                ->execute();
        }

        parent::afterDelete();
    }

    public function afterRestore(): void
    {
        $handle = preg_replace('/--trashed-\d+$/', '', $this->handle) ?? $this->handle;

        // The name may have been reused while this was in the trash. Coming back as `orders-2` is
        // untidy; refusing to come back at all is worse.
        if (self::find()->handle($handle)->status(null)->id(['not', $this->id])->exists()) {
            $handle = $this->generateHandle($handle);
        }

        $this->handle = $handle;

        Craft::$app->getDb()->createCommand()
            ->update(\justinholtweb\reportr\records\Table::REPORTS, [
                'handle' => $handle,
                'nextRunAt' => Db::prepareDateForDb($this->getSchedule()->nextOccurrence()),
            ], ['id' => $this->id])
            ->execute();

        parent::afterRestore();
    }

    // Search
    // -------------------------------------------------------------------------

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'handle', 'description', 'template'];
    }

    // Index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('reportr', 'All reports'),
                'defaultSort' => ['title', 'asc'],
            ],
            ['heading' => Craft::t('reportr', 'Type')],
            [
                'key' => 'type:basic',
                'label' => Craft::t('reportr', 'Basic'),
                'criteria' => ['type' => self::TYPE_BASIC],
            ],
            [
                'key' => 'type:advanced',
                'label' => Craft::t('reportr', 'Advanced'),
                'criteria' => ['type' => self::TYPE_ADVANCED],
            ],
            [
                'key' => 'type:query',
                'label' => Craft::t('reportr', 'Query'),
                'criteria' => ['type' => self::TYPE_QUERY],
            ],
            ['heading' => Craft::t('reportr', 'Automation')],
            [
                'key' => 'scheduled',
                'label' => Craft::t('reportr', 'Scheduled'),
                'criteria' => ['isScheduled' => true],
                'defaultSort' => ['reportr_reports.nextRunAt', 'asc'],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'handle' => ['label' => Craft::t('app', 'Handle')],
            'type' => ['label' => Craft::t('reportr', 'Type')],
            'format' => ['label' => Craft::t('reportr', 'Format')],
            'params' => ['label' => Craft::t('reportr', 'Parameters')],
            'schedule' => ['label' => Craft::t('reportr', 'Schedule')],
            'nextRunAt' => ['label' => Craft::t('reportr', 'Next run')],
            'lastRunAt' => ['label' => Craft::t('reportr', 'Last run')],
            'runCount' => ['label' => Craft::t('reportr', 'Runs')],
            'run' => ['label' => Craft::t('reportr', 'Run')],
            'dateUpdated' => ['label' => Craft::t('app', 'Last Updated')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['handle', 'type', 'format', 'schedule', 'lastRunAt', 'runCount', 'run'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'reportr_reports.handle' => Craft::t('app', 'Handle'),
            'reportr_reports.type' => Craft::t('reportr', 'Type'),
            'reportr_reports.lastRunAt' => Craft::t('reportr', 'Last run'),
            'reportr_reports.nextRunAt' => Craft::t('reportr', 'Next run'),
            'reportr_reports.runCount' => Craft::t('reportr', 'Runs'),
            'dateUpdated' => Craft::t('app', 'Last Updated'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'handle' => Html::tag('code', Html::encode($this->handle), ['class' => 'small light']),
            'type' => Html::encode($this->getTypeLabel()),
            'format' => Html::encode(strtoupper($this->format)),
            'params' => $this->getHasParams()
                ? Html::encode((string)count($this->getParams()))
                : Html::tag('span', '—', ['class' => 'light']),
            'schedule' => $this->getSchedule()->getIsEnabled()
                ? Html::encode($this->getSchedule()->describe())
                : Html::tag('span', Craft::t('reportr', 'Manual'), ['class' => 'light']),
            'runCount' => $this->runCount > 0
                ? Html::a((string)$this->runCount, UrlHelper::cpUrl('reportr/runs', ['reportId' => $this->getCanonicalId()]))
                : Html::tag('span', '0', ['class' => 'light']),
            'run' => $this->runButtonHtml(),
            default => parent::attributeHtml($attribute),
        };
    }

    private function runButtonHtml(): string
    {
        $problem = $this->getBlockingProblem();

        if ($problem !== null) {
            // The reason, not a disabled button with no explanation — which is what Lab Reports'
            // greyed-out Delete action was, and its issue #5.
            return Html::tag('span', Craft::t('reportr', 'Not runnable'), [
                'class' => 'error',
                'title' => $problem,
            ]);
        }

        return Html::a(Craft::t('reportr', 'Run'), $this->getRunUrl(), ['class' => 'btn small']);
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_ENABLED => ['label' => Craft::t('reportr', 'Active'), 'color' => Color::Green],
            self::STATUS_DISABLED => ['label' => Craft::t('app', 'Disabled'), 'color' => Color::Gray],
        ];
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW_REPORTS);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_REPORTS);
    }

    public function canDuplicate(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_REPORTS);
    }

    /**
     * Deletable, unconditionally, by anybody with the permission.
     *
     * Lab Reports' issue #5 is a person looking at a greyed-out Delete action with no way to
     * remove a report they no longer wanted, having already deleted its template. There is no
     * good reason for a configuration to be undeletable, and the runs it produced survive it.
     */
    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_REPORTS);
    }

    public function canCreateDrafts(User $user): bool
    {
        return false;
    }
}
