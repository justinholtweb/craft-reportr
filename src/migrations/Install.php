<?php

declare(strict_types=1);

namespace justinholtweb\reportr\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\reportr\records\Table;

/**
 * Reportr's schema.
 *
 * Three shapes here are decisions rather than defaults, and each one is a bug the plugin this
 * replaces actually shipped:
 *
 * - **A run's `reportId` is `SET NULL`, and the run keeps a copy of the report's title.** Lab
 *   Reports made the generated report's label `$cr->reportTitle` with no null check, so deleting
 *   a configuration turned every one of its past runs into a fatal on the index. Deleting the
 *   recipe must not delete — or break — the meals.
 *
 * - **The file's location is two columns (`fsHandle`, `path`), not one filename.** Lab Reports
 *   stored everything under a local system path, which is why its issue #8 is somebody on Heroku
 *   watching every report say "Unavailable (File Missing)" after each dyno restart. A run that
 *   records *which* filesystem it was written to can live on S3 and can still be found after the
 *   default is changed.
 *
 * - **`runStatus`, not `status`.** `status` is `craft\base\Element::getStatus()`; a column of
 *   that name is shadowed by the getter and reads back as the element's enabled state.
 *   `initiator`, not `trigger`, for the plainer reason that `TRIGGER` is reserved in MySQL.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable(Table::REPORTS, [
            'id' => $this->integer()->notNull(),
            'handle' => $this->string(64)->notNull(),
            'type' => $this->string(16)->notNull()->defaultValue('basic'),
            'description' => $this->text(),
            'template' => $this->string(255),
            'formatFunction' => $this->string(100),
            'format' => $this->string(16)->notNull()->defaultValue('csv'),
            'formatOptions' => $this->text(),
            'params' => $this->text(),
            'querySpec' => $this->mediumText(),
            'filenameFormat' => $this->string(255),
            'fsHandle' => $this->string(64),
            'fsSubpath' => $this->string(255),
            'schedule' => $this->text(),
            'nextRunAt' => $this->dateTime(),
            'lastRunAt' => $this->dateTime(),
            'delivery' => $this->text(),
            'retentionRuns' => $this->integer(),
            'retentionDays' => $this->integer(),
            'batchSize' => $this->integer(),
            'runCount' => $this->integer()->notNull()->defaultValue(0),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'legacyId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, Table::REPORTS, ['handle'], true);
        $this->createIndex(null, Table::REPORTS, ['type'], false);
        // The scheduler's only query: "anything due?" — asked every minute, so it gets an index.
        $this->createIndex(null, Table::REPORTS, ['nextRunAt'], false);
        $this->createIndex(null, Table::REPORTS, ['legacyId'], false);

        $this->addForeignKey(null, Table::REPORTS, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);

        $this->createTable(Table::RUNS, [
            'id' => $this->integer()->notNull(),
            'reportId' => $this->integer(),
            'reportTitle' => $this->string(255),
            'runStatus' => $this->string(16)->notNull()->defaultValue('queued'),
            'statusMessage' => $this->text(),
            'format' => $this->string(16),
            'filename' => $this->string(255),
            'fsHandle' => $this->string(64),
            'path' => $this->string(500),
            'fileSize' => $this->bigInteger(),
            'totalRows' => $this->integer()->notNull()->defaultValue(0),
            'params' => $this->text(),
            'initiator' => $this->string(16)->notNull()->defaultValue('cp'),
            'userId' => $this->integer(),
            'dateStarted' => $this->dateTime(),
            'dateFinished' => $this->dateTime(),
            'durationMs' => $this->integer(),
            'peakMemory' => $this->bigInteger(),
            'legacyId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, Table::RUNS, ['reportId'], false);
        $this->createIndex(null, Table::RUNS, ['runStatus'], false);
        $this->createIndex(null, Table::RUNS, ['dateFinished'], false);
        $this->createIndex(null, Table::RUNS, ['userId'], false);
        $this->createIndex(null, Table::RUNS, ['legacyId'], false);

        $this->addForeignKey(null, Table::RUNS, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::RUNS, ['reportId'], Table::REPORTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::RUNS, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropAllForeignKeysToTable(Table::REPORTS);
        $this->dropTableIfExists(Table::RUNS);
        $this->dropTableIfExists(Table::REPORTS);

        $this->delete(CraftTable::ELEMENTS, [
            'type' => [
                \justinholtweb\reportr\elements\Report::class,
                \justinholtweb\reportr\elements\Run::class,
            ],
        ]);

        return true;
    }
}
