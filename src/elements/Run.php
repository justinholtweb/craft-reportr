<?php

declare(strict_types=1);

namespace justinholtweb\reportr\elements;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\elements\db\EagerLoadPlan;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\reportr\build\Session;
use justinholtweb\reportr\elements\db\RunQuery;
use justinholtweb\reportr\Plugin;
use justinholtweb\reportr\records\RunRecord;
use justinholtweb\reportr\records\Table;

/**
 * One execution of a report, and the file it produced.
 *
 * ## This is the object a template calls
 *
 * Report templates receive this as `report`, and call `report.build(rows)` on it. That naming is
 * inherited rather than chosen: in Lab Reports the variable is called `report` and the build
 * methods live on the generated report, so any other arrangement would mean every imported
 * template needs editing before it runs. Compatibility beats tidiness here — the whole point of
 * this plugin is that somebody can move to it in an afternoon.
 *
 * The methods themselves delegate to a {@see Session}, which owns the writer, the batching and
 * the row counting. The element is a database row; the session is the machinery, and it exists
 * only while a build is in flight.
 *
 * ## What a run remembers
 *
 * More than Lab Reports recorded, and each addition is a question somebody has had to answer by
 * guessing: which parameters it was given, how long it took, how much memory it peaked at, what
 * started it, and *which filesystem the file went to*. The last one is the fix for reports going
 * missing when the default storage changes — a run that only knows a filename cannot find its own
 * file afterwards.
 */
class Run extends Element
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_ERROR = 'error';

    public const INITIATOR_CP = 'cp';
    public const INITIATOR_CONSOLE = 'console';
    public const INITIATOR_SCHEDULE = 'schedule';
    public const INITIATOR_TEMPLATE = 'template';

    public ?int $reportId = null;
    public ?string $reportTitle = null;

    /**
     * `runStatus`, not `status`.
     *
     * `status` is `Element::getStatus()`. A property of that name is shadowed by the getter, and
     * the column reads back as the element's enabled state — a bug that looks like the status
     * never being saved.
     */
    public string $runStatus = self::STATUS_QUEUED;

    public ?string $statusMessage = null;
    public ?string $format = null;
    public ?string $filename = null;
    public ?string $fsHandle = null;
    public ?string $path = null;
    public ?int $fileSize = null;
    public int $totalRows = 0;
    public string $initiator = self::INITIATOR_CP;
    public ?int $userId = null;
    public ?DateTime $dateStarted = null;
    public ?DateTime $dateFinished = null;
    public ?int $durationMs = null;
    public ?int $peakMemory = null;
    public ?int $legacyId = null;

    private array $_params = [];
    private ?Report $_report = null;
    private ?User $_user = null;
    private ?Session $_session = null;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('reportr', 'Run');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('reportr', 'run');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('reportr', 'Runs');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('reportr', 'runs');
    }

    public static function hasTitles(): bool
    {
        return false;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    public static function find(): ElementQueryInterface
    {
        return new RunQuery(static::class);
    }

    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['dateStarted', 'dateFinished']);
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_QUEUED => ['label' => Craft::t('reportr', 'Queued'), 'color' => Color::Gray],
            self::STATUS_RUNNING => ['label' => Craft::t('reportr', 'Running'), 'color' => Color::Blue],
            self::STATUS_FINISHED => ['label' => Craft::t('reportr', 'Finished'), 'color' => Color::Green],
            self::STATUS_ERROR => ['label' => Craft::t('reportr', 'Failed'), 'color' => Color::Red],
        ];
    }

    public function getStatus(): ?string
    {
        return $this->runStatus;
    }

    public function getUiLabel(): string
    {
        $title = $this->reportTitle ?: ($this->getReport()?->title ?? Craft::t('reportr', 'Deleted report'));
        $date = $this->dateFinished ?? $this->dateStarted ?? $this->dateCreated;

        return $date !== null
            ? $title . ' — ' . Craft::$app->getFormatter()->asDatetime($date, 'short')
            : $title;
    }

    public function __toString(): string
    {
        return $this->getUiLabel();
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl('reportr/runs/' . $this->id);
    }

    public function getDownloadUrl(): string
    {
        return UrlHelper::cpUrl('reportr/runs/' . $this->id . '/download');
    }

    /** Lab Reports called this the detail page. Kept as an alias so imported Twig keeps working. */
    public function getDetailPageUrl(): string
    {
        return (string)$this->cpEditUrl();
    }

    // Relations
    // -------------------------------------------------------------------------

    public function getReport(): ?Report
    {
        if ($this->_report !== null) {
            return $this->_report;
        }

        if ($this->reportId === null) {
            return null;
        }

        /** @var Report|null $report */
        $report = Report::find()->id($this->reportId)->status(null)->one();

        return $this->_report = $report;
    }

    public function setReport(?Report $report): static
    {
        $this->_report = $report;
        $this->reportId = $report?->id;
        $this->reportTitle = $report?->title;
        $this->format = $report?->format ?? $this->format;

        return $this;
    }

    /** Lab Reports' name for the same relationship. */
    public function getConfiguredReport(): ?Report
    {
        return $this->getReport();
    }

    public function setConfiguredReport(?Report $report): static
    {
        return $this->setReport($report);
    }

    public function getUser(): ?User
    {
        if ($this->_user !== null) {
            return $this->_user;
        }

        return $this->userId !== null
            ? $this->_user = Craft::$app->getUsers()->getUserById($this->userId)
            : null;
    }

    public function setUser(?User $user): static
    {
        $this->_user = $user;
        $this->userId = $user?->id;

        return $this;
    }

    // Parameters
    // -------------------------------------------------------------------------

    public function getParams(): array
    {
        return $this->_params;
    }

    public function setParams(mixed $value): void
    {
        if (is_string($value)) {
            $value = $value !== '' ? Json::decodeIfJson($value) : [];
        }

        $this->_params = is_array($value) ? $value : [];
    }

    /** @return array<string, string> Parameter label => the answer, in words. For the CP and email. */
    public function describeParams(): array
    {
        $report = $this->getReport();
        $described = [];

        foreach ($this->_params as $name => $value) {
            $param = $report?->getParam((string)$name);

            $described[$param?->label ?? (string)$name] = $param !== null
                ? $param->describe($param->normalize($value))
                : (is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value);
        }

        return $described;
    }

    // The build session — what a report template actually talks to
    // -------------------------------------------------------------------------

    public function setSession(?Session $session): static
    {
        $this->_session = $session;

        return $this;
    }

    public function getSession(): ?Session
    {
        return $this->_session;
    }

    /**
     * Build the report file.
     *
     * Signature-compatible with Lab Reports: a basic report passes one array of rows whose first
     * row is the column headings; an advanced report passes the headings and an *unexecuted*
     * element query.
     */
    public function build(mixed $param1, mixed $param2 = null): int
    {
        return $this->requireSession()->build($param1, $param2);
    }

    public function addRow(array $row): bool
    {
        return $this->requireSession()->addRow($row);
    }

    public function addRows(array $rows): int
    {
        return $this->requireSession()->addRows($rows);
    }

    /** Column headings, for a template that would rather set them explicitly. */
    public function setHeadings(array $headings): static
    {
        $this->requireSession()->setHeadings($headings);

        return $this;
    }

    /**
     * The answers this run was given, as a template sees them.
     *
     * Also exposed as the bare `params` variable, which is what people type; this is here so that
     * a macro that only has the report object can still reach them.
     */
    public function getValues(): array
    {
        return $this->_session?->getParamValues() ?? [];
    }

    private function requireSession(): Session
    {
        if ($this->_session === null) {
            throw new \RuntimeException('This report is not being built. `report.build()` may only be called from a report template while the report is running.');
        }

        return $this->_session;
    }

    // The file
    // -------------------------------------------------------------------------

    public function fileExists(): bool
    {
        return Plugin::getInstance()->storage->exists($this);
    }

    /**
     * The local system path to the file, if it is on local disk.
     *
     * Returns null for a run stored on a Craft filesystem, because there is no such path — which
     * is the honest answer, and the reason `fileExists()` and the download action go through the
     * storage service rather than through `file_exists()`.
     */
    public function filePath(): ?string
    {
        return Plugin::getInstance()->storage->localPath($this);
    }

    public function getFormattedSize(): string
    {
        if (!$this->fileSize) {
            return '—';
        }

        return Craft::$app->getFormatter()->asShortSize($this->fileSize, 1);
    }

    public function getFormattedDuration(): string
    {
        if ($this->durationMs === null) {
            return '—';
        }

        return $this->durationMs < 1000
            ? $this->durationMs . ' ms'
            : Craft::t('reportr', '{seconds}s', ['seconds' => number_format($this->durationMs / 1000, 1)]);
    }

    public function getStatusLabel(): string
    {
        return (string)(self::statuses()[$this->runStatus]['label'] ?? $this->runStatus);
    }

    public function getIsFinished(): bool
    {
        return $this->runStatus === self::STATUS_FINISHED;
    }

    public function getIsDownloadable(): bool
    {
        return $this->getIsFinished() && $this->filename !== null && $this->fileExists();
    }

    /**
     * Move the run to a new status and save it.
     *
     * Saved with validation off. A run mid-build is a progress record, not a document being
     * edited, and a validation failure at this point would lose the status of a build that is
     * still running — including the failure message explaining why it stopped.
     */
    public function updateStatus(string $status, ?string $message = null): bool
    {
        $this->runStatus = $status;

        if ($message !== null) {
            $this->statusMessage = $message;
        }

        return Craft::$app->getElements()->saveElement($this, false, false, false);
    }

    // Saving
    // -------------------------------------------------------------------------

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = $isNew ? new RunRecord() : RunRecord::findOne($this->id);

            if ($record === null) {
                $record = new RunRecord();
                $isNew = true;
            }

            if ($isNew) {
                $record->id = $this->id;
            }

            $record->reportId = $this->reportId;
            $record->reportTitle = $this->reportTitle;
            $record->runStatus = $this->runStatus;
            $record->statusMessage = $this->statusMessage;
            $record->format = $this->format;
            $record->filename = $this->filename;
            $record->fsHandle = $this->fsHandle;
            $record->path = $this->path;
            $record->fileSize = $this->fileSize;
            $record->totalRows = $this->totalRows;
            $record->params = Json::encode($this->_params);
            $record->initiator = $this->initiator;
            $record->userId = $this->userId;
            $record->dateStarted = Db::prepareDateForDb($this->dateStarted);
            $record->dateFinished = Db::prepareDateForDb($this->dateFinished);
            $record->durationMs = $this->durationMs;
            $record->peakMemory = $this->peakMemory;
            $record->legacyId = $this->legacyId;
            $record->save(false);
        }

        parent::afterSave($isNew);
    }

    /**
     * The file goes only on a hard delete.
     *
     * A trashed run has to be restorable, and a restored run whose file was deleted is a row that
     * says "Finished, 40,000 rows" next to a download button that 404s.
     */
    public function afterDelete(): void
    {
        if ($this->hardDelete) {
            Plugin::getInstance()->storage->delete($this);
        }

        parent::afterDelete();
    }

    // Eager loading
    // -------------------------------------------------------------------------

    public static function eagerLoadingMap(array $sourceElements, string $handle): array|false|null
    {
        $ids = ArrayHelper::getColumn($sourceElements, 'id');

        $column = match ($handle) {
            'user' => 'userId',
            'report' => 'reportId',
            default => null,
        };

        if ($column === null) {
            return parent::eagerLoadingMap($sourceElements, $handle);
        }

        return [
            'elementType' => $handle === 'user' ? User::class : Report::class,
            'map' => (new Query())
                ->select(['id as source', $column . ' as target'])
                ->from([Table::RUNS])
                ->where(['and', ['id' => $ids], ['not', [$column => null]]])
                ->all(),
        ];
    }

    public function setEagerLoadedElements(string $handle, array $elements, EagerLoadPlan $plan): void
    {
        match ($handle) {
            'user' => $this->setUser($elements[0] ?? null),
            'report' => $this->setReport($elements[0] ?? null),
            default => parent::setEagerLoadedElements($handle, $elements, $plan),
        };
    }

    // Index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('reportr', 'All runs'),
                'defaultSort' => ['reportr_runs.dateCreated', 'desc'],
            ],
            ['heading' => Craft::t('reportr', 'Status')],
            [
                'key' => 'status:finished',
                'label' => Craft::t('reportr', 'Finished'),
                'criteria' => ['status' => self::STATUS_FINISHED],
            ],
            [
                'key' => 'status:error',
                'label' => Craft::t('reportr', 'Failed'),
                'criteria' => ['status' => self::STATUS_ERROR],
            ],
            [
                'key' => 'status:running',
                'label' => Craft::t('reportr', 'In progress'),
                'criteria' => ['status' => [self::STATUS_QUEUED, self::STATUS_RUNNING]],
            ],
        ];

        // A source per report, which is how somebody actually navigates this screen. Wrapped
        // because the sources are built on every CP request, including ones Craft serves while a
        // migration is half-applied — a query against a table that does not exist yet would take
        // the whole control panel down at the worst possible moment.
        try {
            $reports = Report::find()->status(null)->orderBy(['title' => SORT_ASC])->all();

            if ($reports !== []) {
                $sources[] = ['heading' => Craft::t('reportr', 'Report')];

                foreach ($reports as $report) {
                    $sources[] = [
                        'key' => 'report:' . $report->uid,
                        'label' => $report->title,
                        'criteria' => ['reportId' => $report->id],
                        'defaultSort' => ['reportr_runs.dateCreated', 'desc'],
                    ];
                }
            }
        } catch (\Throwable) {
            // No per-report sources. Nothing else is worth breaking for it.
        }

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'report' => ['label' => Craft::t('reportr', 'Report')],
            'filename' => ['label' => Craft::t('reportr', 'Filename')],
            'format' => ['label' => Craft::t('reportr', 'Format')],
            'totalRows' => ['label' => Craft::t('reportr', 'Rows')],
            'fileSize' => ['label' => Craft::t('reportr', 'Size')],
            'duration' => ['label' => Craft::t('reportr', 'Duration')],
            'params' => ['label' => Craft::t('reportr', 'Parameters')],
            'initiator' => ['label' => Craft::t('reportr', 'Started by')],
            'user' => ['label' => Craft::t('reportr', 'User')],
            'dateFinished' => ['label' => Craft::t('reportr', 'Finished')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'download' => ['label' => Craft::t('reportr', 'Download')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['report', 'totalRows', 'fileSize', 'dateFinished', 'download'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'reportr_runs.dateCreated' => Craft::t('app', 'Date Created'),
            'reportr_runs.dateFinished' => Craft::t('reportr', 'Finished'),
            'reportr_runs.totalRows' => Craft::t('reportr', 'Rows'),
            'reportr_runs.fileSize' => Craft::t('reportr', 'Size'),
            'reportr_runs.durationMs' => Craft::t('reportr', 'Duration'),
            'reportr_runs.filename' => Craft::t('reportr', 'Filename'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'report' => $this->reportHtml(),
            'filename' => Html::a(Html::encode((string)$this->filename), (string)$this->cpEditUrl()),
            'format' => Html::encode(strtoupper((string)$this->format)),
            'totalRows' => Html::encode(number_format($this->totalRows)),
            'fileSize' => Html::encode($this->getFormattedSize()),
            'duration' => Html::encode($this->getFormattedDuration()),
            'params' => $this->paramsHtml(),
            'initiator' => Html::encode(self::initiatorLabels()[$this->initiator] ?? $this->initiator),
            'user' => Html::encode((string)($this->getUser()?->friendlyName ?? '—')),
            'download' => $this->downloadHtml(),
            default => parent::attributeHtml($attribute),
        };
    }

    public static function initiatorLabels(): array
    {
        return [
            self::INITIATOR_CP => Craft::t('reportr', 'Control panel'),
            self::INITIATOR_CONSOLE => Craft::t('reportr', 'Console'),
            self::INITIATOR_SCHEDULE => Craft::t('reportr', 'Schedule'),
            self::INITIATOR_TEMPLATE => Craft::t('reportr', 'Template'),
        ];
    }

    private function reportHtml(): string
    {
        $report = $this->getReport();

        if ($report !== null) {
            return Html::a(Html::encode($report->title), (string)$report->getCpEditUrl());
        }

        // The report was deleted. The run still knows what it was called, which is the whole
        // reason that column exists — the alternative is a screen full of "Unknown".
        return Html::tag('span', Html::encode($this->reportTitle ?: Craft::t('reportr', 'Deleted report')), [
            'class' => 'light',
        ]);
    }

    private function paramsHtml(): string
    {
        $described = $this->describeParams();

        if ($described === []) {
            return Html::tag('span', '—', ['class' => 'light']);
        }

        $summary = implode(', ', array_map(
            static fn(string $label, string $value) => $label . ': ' . $value,
            array_keys($described),
            array_values($described),
        ));

        return Html::tag('span', Html::encode(mb_strimwidth($summary, 0, 60, '…')), [
            'class' => 'small',
            'title' => $summary,
        ]);
    }

    private function downloadHtml(): string
    {
        if ($this->runStatus === self::STATUS_ERROR) {
            return Html::tag('span', Craft::t('reportr', 'Failed'), ['class' => 'error']);
        }

        if (!$this->getIsFinished()) {
            return Html::tag('span', $this->getStatusLabel(), ['class' => 'light']);
        }

        if (!$this->fileExists()) {
            // Says *where* it went looking, because "Unavailable (File Missing)" with no further
            // information is Lab Reports issue #8 and cost its reporter a support thread.
            $where = $this->fsHandle
                ? Craft::t('reportr', 'Missing from the “{fs}” filesystem', ['fs' => $this->fsHandle])
                : Craft::t('reportr', 'Missing from local storage');

            return Html::tag('span', Craft::t('reportr', 'File missing'), ['class' => 'error', 'title' => $where]);
        }

        return Html::a(Craft::t('app', 'Download'), $this->getDownloadUrl(), ['class' => 'btn small']);
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW_RUNS);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW_RUNS);
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_DELETE_RUNS);
    }

    public function canDuplicate(User $user): bool
    {
        return false;
    }
}
