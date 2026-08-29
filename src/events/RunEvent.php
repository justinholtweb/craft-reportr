<?php

declare(strict_types=1);

namespace justinholtweb\reportr\events;

use craft\events\CancelableEvent;
use justinholtweb\reportr\elements\Report;
use justinholtweb\reportr\elements\Run;

/**
 * Raised around a report run.
 *
 * Extends `CancelableEvent`, not `yii\base\Event` — reading `$event->isValid` on a plain base
 * event throws `UnknownPropertyException` the first time anything looks at it, which is in the
 * one code path the cancellable event exists for.
 */
class RunEvent extends CancelableEvent
{
    public Report $report;
    public Run $run;
}
