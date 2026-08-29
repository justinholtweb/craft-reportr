<?php

declare(strict_types=1);

namespace justinholtweb\reportr\records;

/**
 * Reportr's table names, in one place.
 *
 * Every query and every migration reads the name from here, so a typo cannot make the schema and
 * the code disagree on only one database driver.
 */
abstract class Table
{
    public const REPORTS = '{{%reportr_reports}}';
    public const RUNS = '{{%reportr_runs}}';
}
