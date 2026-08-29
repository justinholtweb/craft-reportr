<?php

declare(strict_types=1);

namespace justinholtweb\reportr\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Reportr's settings.
 *
 * Nothing here is marked `required`. Craft validates the whole settings model on every save, so
 * one required field makes a fresh install unable to save *any* setting until that field is
 * filled — a trap the family has been caught by before.
 */
class Settings extends Model
{
    /**
     * Where report files go when a report does not name a filesystem of its own.
     *
     * A Craft filesystem handle, or `null` for a folder on local disk. Naming one is the answer
     * to Lab Reports issue #8 — reports vanishing on Heroku — because a platform with an
     * ephemeral disk needs the file to leave the dyno before the dyno does.
     */
    public ?string $fsHandle = null;

    /** Subfolder within that filesystem. Supports `{{ report.handle }}`-free plain paths only. */
    public string $fsSubpath = 'reports';

    /** Local fallback. Empty means `storage/reportr`. */
    public string $storageFolder = '';

    /** Rows fetched per batch when a report walks a query. */
    public int $batchSize = 100;

    /**
     * Seconds a queued build is allowed to take before the queue reclaims it.
     *
     * Craft's default is 300, which is where Lab Reports issue #7 comes from: a nightly export
     * that grew past five minutes started failing with "exceeded the timeout of 300 seconds" and
     * there was no setting to raise. An hour is a sane ceiling for a report; a build that is
     * still going after that has something else wrong with it.
     */
    public int $jobTtr = 3600;

    /** PHP memory limit applied for the duration of a build. Empty leaves it alone. */
    public string $memoryLimit = '512M';

    /** PHP time limit applied for the duration of a build. 0 leaves it alone. */
    public int $timeLimit = 0;

    /** Rows shown by the preview, which never writes a file or records a run. */
    public int $previewRows = 25;

    /** Default retention. Either can be 0 for "keep everything". */
    public int $retentionRuns = 0;
    public int $retentionDays = 0;

    /** Largest attachment, in megabytes, that a delivery email will carry. */
    public int $maxAttachmentMb = 10;

    /** Fire due schedules from control-panel traffic, the way Craft runs its queue. */
    public bool $runScheduleAutomatically = true;

    /**
     * Read `config/labreports.php`'s `functions` array as well as Reportr's own.
     *
     * The one setting that exists purely for people arriving from the other plugin: their
     * advanced reports' formatting functions live in that file, and a migration that requires
     * moving them before anything works is a migration people abandon halfway.
     */
    public bool $readLabReportsConfig = true;

    public bool $debug = false;

    public function defineRules(): array
    {
        return [
            [['batchSize'], 'integer', 'min' => 1, 'max' => 10000],
            [['jobTtr'], 'integer', 'min' => 30, 'max' => 86400],
            [['timeLimit'], 'integer', 'min' => 0, 'max' => 86400],
            [['previewRows'], 'integer', 'min' => 1, 'max' => 1000],
            [['retentionRuns', 'retentionDays'], 'integer', 'min' => 0],
            [['maxAttachmentMb'], 'integer', 'min' => 0, 'max' => 100],
            [['fsSubpath', 'storageFolder', 'memoryLimit'], 'string'],
            [['fsHandle'], 'validateFsHandle'],
        ];
    }

    public function validateFsHandle(): void
    {
        if ($this->fsHandle === null || $this->fsHandle === '') {
            return;
        }

        if (Craft::$app->getFs()->getFilesystemByHandle($this->fsHandle) === null) {
            $this->addError('fsHandle', Craft::t('reportr', 'No filesystem with the handle “{handle}”.', [
                'handle' => $this->fsHandle,
            ]));
        }
    }

    /**
     * Cast every posted value to the type its property declares.
     *
     * A control-panel number field posts `''` when it is cleared, and assigning that to a typed
     * `int` property is a `TypeError` — the author empties a box and the whole save fatals with a
     * stack trace instead of a validation message.
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (!is_array($values)) {
            parent::setAttributes($values, $safeOnly);

            return;
        }

        foreach ($values as $name => $value) {
            if (!property_exists($this, $name)) {
                continue;
            }

            $type = (new ReflectionProperty($this, $name))->getType();

            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            $values[$name] = match ($type->getName()) {
                'int' => is_numeric($value) ? (int)$value : ($type->allowsNull() ? null : $this->$name),
                'bool' => is_array($value) ? (bool)end($value) : (bool)$value,
                'string' => $value === null ? ($type->allowsNull() ? null : '') : (string)(is_array($value) ? reset($value) : $value),
                default => $value,
            };

            if ($type->allowsNull() && $type->getName() === 'string' && $values[$name] === '') {
                $values[$name] = null;
            }
        }

        parent::setAttributes($values, $safeOnly);
    }

    /** The resolved local folder, whether or not it exists yet. */
    public function getStoragePath(): string
    {
        $folder = trim((string)App::parseEnv($this->storageFolder));

        if ($folder === '') {
            return Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . 'reportr';
        }

        return rtrim($folder, '/\\');
    }

    public function getMaxAttachmentBytes(): int
    {
        return max(0, $this->maxAttachmentMb) * 1024 * 1024;
    }
}
