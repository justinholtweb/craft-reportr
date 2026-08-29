<?php

declare(strict_types=1);

namespace justinholtweb\reportr\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use justinholtweb\reportr\elements\Report;
use justinholtweb\reportr\elements\Run;
use justinholtweb\reportr\Plugin;
use justinholtweb\reportr\records\Table;
use Throwable;

/**
 * Fires reports whose time has come.
 *
 * ## Why the next run is stored rather than computed
 *
 * "Is anything due?" is asked every minute forever. Evaluating a recurrence rule per report per
 * tick is work that scales with the number of reports and answers "no" almost every time;
 * comparing one indexed column against `now()` does not. The stored value is also what the
 * control panel shows, so the schedule a person reads is the schedule that will actually run.
 *
 * ## The claim happens before the work
 *
 * `nextRunAt` is advanced *before* the job is queued, in a conditional update that only succeeds
 * if the row still holds the value it was read with. Two schedulers running at once — a cron
 * entry and the control-panel fallback, which is exactly the combination people end up with —
 * therefore cannot both fire the same report. Doing it the other way round produces duplicate
 * exports on a busy site and nothing at all to explain them.
 */
class Schedules extends Component
{
    /** How often the control-panel fallback is allowed to look. */
    private const FALLBACK_INTERVAL = 60;

    private const CACHE_KEY = 'reportr.schedule.lastCheck';

    /** @return Report[] Reports whose next run has arrived. */
    public function due(?DateTime $now = null): array
    {
        /** @var Report[] */
        return Report::find()
            ->status(Report::STATUS_ENABLED)
            ->dueBefore($now ?? new DateTime('now'))
            ->orderBy(['reportr_reports.nextRunAt' => SORT_ASC])
            ->all();
    }

    /**
     * Run everything that is due.
     *
     * @param bool $inline Build here rather than queueing. What the console command wants when a
     *                     site has no queue runner, and what a cron entry running `queue/run`
     *                     separately does not.
     * @return Run[] The runs that were started.
     */
    public function runDue(?DateTime $now = null, bool $inline = false): array
    {
        $now ??= new DateTime('now');
        $runner = Plugin::getInstance()->runner;
        $started = [];

        foreach ($this->due($now) as $report) {
            if (!$this->claim($report, $now)) {
                continue;
            }

            try {
                $started[] = $inline
                    ? $runner->run($report, $this->defaultParams($report), Run::INITIATOR_SCHEDULE, null)
                    : $runner->enqueue($report, $this->defaultParams($report), Run::INITIATOR_SCHEDULE, null);
            } catch (Throwable $e) {
                Craft::error(sprintf('Reportr could not start the scheduled report “%s”: %s', $report->title, $e->getMessage()), 'reportr');
            }
        }

        return $started;
    }

    /**
     * Move the report's next run forward, but only if nobody else already has.
     *
     * The `WHERE nextRunAt = <what we read>` clause is the lock. It costs nothing and it is the
     * difference between "the schedule fired once" and "the schedule fired once per scheduler".
     */
    private function claim(Report $report, DateTime $now): bool
    {
        $current = $report->nextRunAt;

        if ($current === null) {
            return false;
        }

        $next = $report->getSchedule()->nextOccurrence($now);

        $affected = Craft::$app->getDb()->createCommand()
            ->update(Table::REPORTS, [
                'nextRunAt' => Db::prepareDateForDb($next),
            ], [
                'id' => $report->id,
                'nextRunAt' => Db::prepareDateForDb($current),
            ])
            ->execute();

        if ($affected > 0) {
            $report->nextRunAt = $next;

            return true;
        }

        return false;
    }

    /**
     * A scheduled report answers its own parameters with their defaults.
     *
     * Which is what makes relative dates worth having: a `since` parameter defaulting to
     * `-7 days` means the last seven days *from each run*, not from the day somebody typed it.
     */
    private function defaultParams(Report $report): array
    {
        $params = [];

        foreach ($report->getParams() as $param) {
            $params[$param->name] = $param->default;
        }

        return $params;
    }

    /**
     * The control-panel fallback, modelled on Craft's own `runQueueAutomatically`.
     *
     * Throttled through the cache so it costs one cache read on a normal request. It is a
     * convenience, not a guarantee — a site whose control panel nobody opens for a week will not
     * run its Monday report, which is why the console command exists and why the settings screen
     * says so.
     */
    public function runAutomatically(): void
    {
        if (!Plugin::getInstance()->getSettings()->runScheduleAutomatically) {
            return;
        }

        $cache = Craft::$app->getCache();

        if ($cache->get(self::CACHE_KEY) !== false) {
            return;
        }

        $cache->set(self::CACHE_KEY, time(), self::FALLBACK_INTERVAL);

        try {
            $this->runDue();
        } catch (Throwable $e) {
            Craft::error('Reportr’s schedule check failed: ' . $e->getMessage(), 'reportr');
        }
    }

    /**
     * Recompute every stored next-run time.
     *
     * For after a timezone change, or a restored database whose stored times are from another
     * machine's clock — both of which otherwise leave reports either firing at the wrong hour or
     * not at all, with a control panel confidently showing the wrong answer.
     */
    public function refreshAll(): int
    {
        $updated = 0;

        foreach (Report::find()->status(null)->all() as $report) {
            /** @var Report $report */
            $next = $report->enabled ? $report->getSchedule()->nextOccurrence() : null;

            Craft::$app->getDb()->createCommand()
                ->update(Table::REPORTS, ['nextRunAt' => Db::prepareDateForDb($next)], ['id' => $report->id])
                ->execute();

            $updated++;
        }

        return $updated;
    }
}
