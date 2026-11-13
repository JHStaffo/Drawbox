<?php

namespace App\Wellness;

use App\Models\User;
use App\Wellness\Models\Point;
use App\Wellness\Models\Reading;
use App\Wellness\Models\Rule;
use Carbon\CarbonImmutable;

/**
 * What a Team Member sees about themselves: today, this week, streaks and
 * history. Returned as plain arrays so the web page and the API share it.
 */
class Progress
{
    public function __construct(private Scoring $scoring) {}

    /** One day: each daily habit with its value, where it came from and its points. */
    public function day(User $user, string $day): array
    {
        $values = $this->scoring->bestValues($user, $day);
        $points = Point::where('user_id', $user->id)->where('day', $day)->where('category', 'daily')->get();
        $habits = [];
        foreach (Rule::forDay($day)->filter(fn (Rule $r) => $r->isDaily()) as $rule) {
            $value = $values[$rule->metric] ?? null;
            $habits[] = [
                'key' => $rule->key,
                'name' => $rule->name,
                'metric' => $rule->metric,
                'goal' => $rule->thresholdLabel(),
                'value' => $value['value'] ?? null,
                'value_label' => Wellness::show($rule->metric, $value['value'] ?? null),
                'source' => $value['source'] ?? null,
                'source_label' => isset($value['source']) ? (Reading::SOURCES[$value['source']] ?? $value['source']) : null,
                'points' => (int) $points->where('activity', $rule->key)->where('kind', 'earned')->sum('points'),
                'max' => $rule->dailyMax(),
            ];
        }
        $adjusted = (int) $points->where('kind', '!=', 'earned')->sum('points');

        return [
            'day' => $day,
            'points' => (int) $points->sum('points'),
            'adjustments' => $adjusted,
            'max' => Scoring::dailyMax(),
            'locked' => Calendar::isLocked($day),
            'test_period' => Calendar::isTestDay($day),
            'habits' => $habits,
        ];
    }

    /** The Sunday to Saturday week holding a day, with progress toward the weekly reward. */
    public function week(User $user, ?string $day = null): array
    {
        $days = Calendar::weekDays($day);
        $from = $days[0]->toDateString();
        $to = $days[6]->toDateString();
        $byDay = $this->scoring->dailyPointsBetween($user, $from, $to);
        $total = array_sum($byDay);
        $target = Scoring::weeklyTarget();
        $today = Calendar::today();

        return [
            'from' => $from,
            'to' => $to,
            'points' => $total,
            'target' => $target,
            'qualified' => $total >= $target,
            'days' => array_map(fn (CarbonImmutable $d) => [
                'day' => $d->toDateString(),
                'label' => $d->format('D'),
                'points' => $byDay[$d->toDateString()] ?? 0,
                'future' => $d->gt($today),
            ], $days),
        ];
    }

    /**
     * Days in a row, up to today, with at least one daily-habit point. A day
     * not logged yet today doesn't break the streak until it's over.
     */
    public function streak(User $user): int
    {
        $days = Point::where('user_id', $user->id)->where('category', 'daily')
            ->where('day', '>=', Calendar::today()->subDays(400)->toDateString())
            ->groupBy('day')->havingRaw('SUM(points) > 0')->pluck('day')
            ->map(fn ($d) => substr((string) $d, 0, 10))->flip();

        $date = Calendar::today();
        if (! isset($days[$date->toDateString()])) {
            $date = $date->subDay();
        }
        $streak = 0;
        while (isset($days[$date->toDateString()])) {
            $streak++;
            $date = $date->subDay();
        }

        return $streak;
    }

    /**
     * Daily-habit points for each of the last N days, oldest first.
     *
     * @return array<int, array{day: string, points: int}>
     */
    public function history(User $user, int $days = 30): array
    {
        $from = Calendar::today()->subDays($days - 1);
        $byDay = $this->scoring->dailyPointsBetween($user, $from->toDateString(), Calendar::today()->toDateString());

        return array_map(function ($i) use ($from, $byDay) {
            $day = $from->addDays($i)->toDateString();

            return ['day' => $day, 'points' => $byDay[$day] ?? 0];
        }, range(0, $days - 1));
    }

    /** Totals for the whole program so far. */
    public function totals(User $user): array
    {
        $rows = Point::where('user_id', $user->id)
            ->selectRaw('day, category, SUM(points) as total')->groupBy('day', 'category')->get();
        $daily = $rows->where('category', 'daily');

        return [
            'points' => (int) $rows->sum('total'),
            'daily_points' => (int) $daily->sum('total'),
            'other_points' => (int) $rows->where('category', 'other')->sum('total'),
            'days_with_points' => $daily->filter(fn ($r) => $r->total > 0)->count(),
            'perfect_days' => $daily->filter(fn ($r) => $r->total >= Scoring::dailyMax())->count(),
        ];
    }

    /** Everything the dashboard shows, in one array. */
    public function summary(User $user): array
    {
        $today = Calendar::today()->toDateString();

        return [
            'today' => $this->day($user, $today),
            'week' => $this->week($user, $today),
            'streak' => $this->streak($user),
            'totals' => $this->totals($user),
            'history' => $this->history($user),
            'last_sync' => $this->lastSync($user),
        ];
    }

    /** The newest reading from each wearable source, so people can see their sync is working. */
    public function lastSync(User $user): array
    {
        return Reading::where('user_id', $user->id)->where('source', '!=', 'manual')->where('source', '!=', 'admin')
            ->selectRaw('source, MAX(updated_at) as last')->groupBy('source')->get()
            ->map(fn ($r) => ['source' => $r->source, 'source_label' => Reading::SOURCES[$r->source] ?? $r->source, 'at' => CarbonImmutable::parse($r->last)->toIso8601String()])
            ->values()->all();
    }
}
