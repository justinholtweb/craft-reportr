<?php

declare(strict_types=1);

namespace justinholtweb\reportr\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property string $handle
 * @property string $type
 * @property string|null $description
 * @property string|null $template
 * @property string|null $formatFunction
 * @property string $format
 * @property string|null $formatOptions
 * @property string|null $params
 * @property string|null $querySpec
 * @property string|null $filenameFormat
 * @property string|null $fsHandle
 * @property string|null $fsSubpath
 * @property string|null $schedule
 * @property string|null $nextRunAt
 * @property string|null $lastRunAt
 * @property string|null $delivery
 * @property int|null $retentionRuns
 * @property int|null $retentionDays
 * @property int|null $batchSize
 * @property int $runCount
 * @property int|null $sortOrder
 * @property int|null $legacyId
 */
class ReportRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::REPORTS;
    }

    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }
}
