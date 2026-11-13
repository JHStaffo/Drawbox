<?php

namespace App\Wellness;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Days, weeks and contest periods for the wellness program. A day is a
 * calendar day in the app's time zone (Eastern); weeks run Sunday to Saturday.
 */
class Calendar
{
    public static function date(string|\DateTimeInterface|null $day = null): CarbonImmutable
    {
        return $day === null ? CarbonImmutable::today() : CarbonImmutable::parse($day)->startOfDay();
    }

    public static function weekStart(string|\DateTimeInterface|null $day = null): CarbonImmutable
    {
        return self::date($day)->startOfWeek(CarbonImmutable::SUNDAY);
    }

    public static function weekEnd(string|\DateTimeInterface|null $day = null): CarbonImmutable
    {
        return self::weekStart($day)->addDays(6);
    }

    /** The days of the Sunday to Saturday week holding this day. */
    public static function weekDays(string|\DateTimeInterface|null $day = null): array
    {
        $start = self::weekStart($day);

        return array_map(fn ($i) => $start->addDays($i), range(0, 6));
    }

    /** Late syncs and corrections are accepted this many days after a week ends. */
    public static function graceDays(): int
    {
        return max(0, (int) Setting::get('wellness_grace_days'));
    }

    /**
     * True once a day can no longer change on its own: the grace period after
     * its week has passed. Only an admin adjustment, with a reason, changes it then.
     */
    public static function isLocked(string|\DateTimeInterface $day): bool
    {
        return self::today()->gt(self::weekEnd($day)->addDays(self::graceDays()));
    }

    /** True for days that can still be logged: not in the future and not locked. */
    public static function isOpen(string|\DateTimeInterface $day): bool
    {
        $date = self::date($day);

        return $date->lte(self::today()) && ! self::isLocked($date);
    }

    /** The first day whose points count toward official prizes. Earlier days are the test period. */
    public static function officialStart(): CarbonImmutable
    {
        return self::date(Setting::get('wellness_official_start'));
    }

    public static function isTestDay(string|\DateTimeInterface $day): bool
    {
        return self::date($day)->lt(self::officialStart());
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::today();
    }

    /**
     * First and last day of the week, month, quarter or year holding a day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function period(string $period, string|\DateTimeInterface|null $day = null): array
    {
        $date = self::date($day);

        return match ($period) {
            'week' => [self::weekStart($date), self::weekEnd($date)],
            'month' => [$date->startOfMonth(), $date->endOfMonth()->startOfDay()],
            'quarter' => [$date->startOfQuarter(), $date->endOfQuarter()->startOfDay()],
            'year' => [$date->startOfYear(), $date->endOfYear()->startOfDay()],
        };
    }

    public static function periodLabel(string $period, string|\DateTimeInterface|null $day = null): string
    {
        [$from, $to] = self::period($period, $day);

        return match ($period) {
            'week' => 'Week of '.$from->format('M j').' to '.$to->format('M j, Y'),
            'month' => $from->format('F Y'),
            'quarter' => 'Q'.$from->quarter.' '.$from->year,
            'year' => (string) $from->year,
        };
    }
}
