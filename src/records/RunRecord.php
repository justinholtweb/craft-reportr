<?php

declare(strict_types=1);

namespace justinholtweb\reportr\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int|null $reportId
 * @property string|null $reportTitle
 * @property string $runStatus
 * @property string|null $statusMessage
 * @property string|null $format
 * @property string|null $filename
 * @property string|null $fsHandle
 * @property string|null $path
 * @property int|null $fileSize
 * @property int $totalRows
 * @property string|null $params
 * @property string $initiator
 * @property int|null $userId
 * @property string|null $dateStarted
 * @property string|null $dateFinished
 * @property int|null $durationMs
 * @property int|null $peakMemory
 * @property int|null $legacyId
 */
class RunRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::RUNS;
    }

    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }
}
