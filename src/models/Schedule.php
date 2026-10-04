<?php

declare(strict_types=1);

namespace justinholtweb\reportr\models;

use Craft;
use craft\base\Model;
use DateTime;
use DateTimeZone;

/**
 * When a report should run on its own.
 *
 * Lab Reports' answer to "run this every morning" was a README section about crontab, and its
 * issue #7 is somebody who followed it and hit the queue's 300-second TTR. Both halves of that
 * are worth fixing: the schedule belongs next to the report rather than in a file on a server
 * that nobody who edits reports can see, and the run it produces needs a TTR chosen for a report
 * rather than for a thumbnail.
 *
 * The next occurrence is computed and *stored* (`reportr_reports.nextRunAt`) rather than derived
 * on every tick. That makes "what is due?" one indexed comparison instead of a rule evaluated per
 * report per minute, and it means the CP can show the answer without recomputing it.
 *
 * Times are wall-clock in the site's timezone, converted to UTC only for storage — "the Monday
 * report at 07:00" has to stay at 07:00 across a daylight-saving boundary, which it does not if
 * the schedule is stored as a fixed UTC offset.
 */
class Schedule extends Model
{
    public const FREQUENCY_NEVER = 'never';
    public const FREQUENCY_HOURLY = 'hourly';
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';

    public string $frequency = self::FREQUENCY_NEVER;

    /** `HH:MM`, in the site's timezone. Ignored when the frequency is hourly. */
    public string $time = '06:00';

    /** 1 (Monday) – 7 (Sunday), ISO-8601, for weekly schedules. */
    public int $weekday = 1;

    /** 1–31 for monthly schedules. A 31 in February lands on the 28th, never in March. */
    public int $monthday = 1;

    /** Minute of the hour for hourly schedules. */
    public int $minute = 0;

    public static function frequencies(): array
    {
        return [
            self::FREQUENCY_NEVER => Craft::t('reportr', 'Never'),
            self::FREQUENCY_HOURLY => Craft::t('reportr', 'Hourly'),
            self::FREQUENCY_DAILY => Craft::t('reportr', 'Daily'),
            self::FREQUENCY_WEEKLY => Craft::t('reportr', 'Weekly'),
            self::FREQUENCY_MONTHLY => Craft::t('reportr', 'Monthly'),
        ];
    }

    public static function weekdays(): array
    {
        return [
            1 => Craft::t('app', 'Monday'),
            2 => Craft::t('app', 'Tuesday'),
            3 => Craft::t('app', 'Wednesday'),
            4 => Craft::t('app', 'Thursday'),
            5 => Craft::t('app', 'Friday'),
            6 => Craft::t('app', 'Saturday'),
            7 => Craft::t('app', 'Sunday'),
        ];
    }

    public static function fromArray(?array $config): self
    {
        $schedule = new self();

        if ($config === null) {
            return $schedule;
        }

        $schedule->frequency = (string)($config['frequency'] ?? self::FREQUENCY_NEVER);

        if (!isset(self::frequencies()[$schedule->frequency])) {
            $schedule->frequency = self::FREQUENCY_NEVER;
        }

        $time = trim((string)($config['time'] ?? '06:00'));
        $schedule->time = preg_match('/^\d{1,2}:\d{2}$/', $time) === 1
            ? sprintf('%02d:%02d', ...array_map('intval', explode(':', $time)))
            : '06:00';

        $schedule->weekday = max(1, min(7, (int)($config['weekday'] ?? 1)));
        $schedule->monthday = max(1, min(31, (int)($config['monthday'] ?? 1)));
        $schedule->minute = max(0, min(59, (int)($config['minute'] ?? 0)));

        return $schedule;
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'frequency' => $this->frequency,
            'time' => $this->time,
            'weekday' => $this->weekday,
            'monthday' => $this->monthday,
            'minute' => $this->minute,
        ];
    }

    public function getIsEnabled(): bool
    {
        return $this->frequency !== self::FREQUENCY_NEVER;
    }

    /**
     * The next moment this schedule fires, strictly after `$after`.
     *
     * Strictly after, always: computing it inclusively means a report that has just run is
     * immediately due again, and the scheduler runs it in a loop until somebody notices.
     */
    public function nextOccurrence(?DateTime $after = null): ?DateTime
    {
        if (!$this->getIsEnabled()) {
            return null;
        }

        $tz = new DateTimeZone(Craft::$app->getTimeZone());
        $from = $after !== null ? (clone $after) : new DateTime('now', $tz);
        $from = $from->setTimezone($tz);

        [$hour, $minute] = array_map('intval', explode(':', $this->time));

        $next = match ($this->frequency) {
            self::FREQUENCY_HOURLY => $this->nextHourly($from, $tz),
            self::FREQUENCY_DAILY => (clone $from)->setTime($hour, $minute, 0),
            self::FREQUENCY_WEEKLY => $this->nextWeekly($from, $hour, $minute),
            self::FREQUENCY_MONTHLY => $this->nextMonthly($from, $hour, $minute),
            default => null,
        };

        if ($next === null) {
            return null;
        }

        // One step forward if the computed slot is in the past — the daily and weekly cases can
        // both land on "today, earlier".
        $guard = 0;

        while ($next <= $from && $guard++ < 40) {
            $next = match ($this->frequency) {
                self::FREQUENCY_HOURLY => $next->modify('+1 hour'),
                self::FREQUENCY_DAILY => $next->modify('+1 day'),
                self::FREQUENCY_WEEKLY => $next->modify('+7 days'),
                default => $this->clampToMonth((clone $next)->modify('first day of next month'), $hour, $minute),
            };
        }

        return $next->setTimezone(new DateTimeZone('UTC'));
    }

    private function nextHourly(DateTime $from, DateTimeZone $tz): DateTime
    {
        return (clone $from)->setTime((int)$from->format('G'), $this->minute, 0);
    }

    private function nextWeekly(DateTime $from, int $hour, int $minute): DateTime
    {
        $next = (clone $from)->setTime($hour, $minute, 0);
        $currentWeekday = (int)$next->format('N');
        $delta = ($this->weekday - $currentWeekday + 7) % 7;

        return $delta === 0 ? $next : $next->modify("+{$delta} days");
    }

    private function nextMonthly(DateTime $from, int $hour, int $minute): DateTime
    {
        return $this->clampToMonth((clone $from)->setTime($hour, $minute, 0), $hour, $minute);
    }

    /**
     * "The 31st" in a month that has 30 days means the 30th, not the 1st of the month after.
     *
     * PHP's own date arithmetic disagrees — `setDate(2026, 2, 31)` overflows into March — and a
     * month-end report that silently moves to the following month is the kind of wrong that is
     * only noticed at the year end.
     */
    private function clampToMonth(DateTime $date, int $hour, int $minute): DateTime
    {
        $daysInMonth = (int)$date->format('t');
        $day = min($this->monthday, $daysInMonth);

        return (clone $date)
            ->setDate((int)$date->format('Y'), (int)$date->format('n'), $day)
            ->setTime($hour, $minute, 0);
    }

    public function describe(): string
    {
        return match ($this->frequency) {
            self::FREQUENCY_HOURLY => Craft::t('reportr', 'Every hour at {minute} past', ['minute' => $this->minute]),
            self::FREQUENCY_DAILY => Craft::t('reportr', 'Every day at {time}', ['time' => $this->time]),
            self::FREQUENCY_WEEKLY => Craft::t('reportr', 'Every {day} at {time}', [
                'day' => self::weekdays()[$this->weekday] ?? '',
                'time' => $this->time,
            ]),
            self::FREQUENCY_MONTHLY => Craft::t('reportr', 'Day {day} of every month at {time}', [
                'day' => $this->monthday,
                'time' => $this->time,
            ]),
            default => Craft::t('reportr', 'Not scheduled'),
        };
    }
}
