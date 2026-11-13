<?php

namespace App\Wellness;

use App\Models\Setting;
use App\Models\User;
use App\Wellness\Models\Point;
use App\Wellness\Models\Reading;
use App\Wellness\Models\Rule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a day's readings into daily-habit points. The best value for each
 * metric across all sources is used, so the same steps coming from Apple
 * Health and from a typed entry never count twice. Each rule caps its own
 * points (3 for meals, 1 for the rest), and the day as a whole never goes
 * over the daily maximum (8).
 */
class Scoring
{
    public static function dailyMax(): int
    {
        return max(1, (int) Setting::get('wellness_daily_max'));
    }

    /** Points for 7 perfect days: what the weekly reward needs. */
    public static function weeklyTarget(): int
    {
        return self::dailyMax() * 7;
    }

    /**
     * The best value per metric for a day, with the source it came from.
     *
     * @return array<string, array{value: float, source: string}>
     */
    public function bestValues(User $user, string $day): array
    {
        return Reading::where('user_id', $user->id)->where('day', $day)->get()
            ->groupBy('metric')
            ->map(function (Collection $readings) {
                $best = $readings->sortByDesc('value')->first();

                return ['value' => $best->value, 'source' => $best->source];
            })->all();
    }

    /**
     * Work out a day's daily-habit points again from its readings. Does
     * nothing once the day is locked, so a late sync can't change history.
     *
     * @return int the day's daily-habit points
     */
    public function scoreDay(User $user, string $day): int
    {
        if (Calendar::isLocked($day)) {
            return $this->dailyPoints($user, $day);
        }

        $values = $this->bestValues($user, $day);
        $rows = [];
        // Adjustments on the day count toward the daily maximum too.
        $adjusted = (int) Point::where('user_id', $user->id)->where('day', $day)->where('category', 'daily')->where('kind', '!=', 'earned')->sum('points');
        $left = max(0, self::dailyMax() - $adjusted);
        foreach (Rule::forDay($day)->filter(fn (Rule $r) => $r->isDaily()) as $rule) {
            $points = min($rule->pointsFor($values[$rule->metric]['value'] ?? null), $left);
            if ($points > 0) {
                $rows[] = ['rule' => $rule, 'points' => $points];
                $left -= $points;
            }
        }

        DB::transaction(function () use ($user, $day, $rows) {
            Point::where('user_id', $user->id)->where('day', $day)->where('category', 'daily')->where('kind', 'earned')->delete();
            foreach ($rows as $row) {
                Point::create([
                    'user_id' => $user->id, 'day' => $day, 'rule_id' => $row['rule']->id,
                    'activity' => $row['rule']->key, 'category' => 'daily', 'points' => $row['points'], 'kind' => 'earned',
                ]);
            }
        });

        return array_sum(array_column($rows, 'points')) + $adjusted;
    }

    /** A day's daily-habit points as they stand in the ledger, adjustments included. */
    public function dailyPoints(User $user, string $day): int
    {
        return (int) Point::where('user_id', $user->id)->where('day', $day)->where('category', 'daily')->sum('points');
    }

    /**
     * Daily-habit points per day between two dates.
     *
     * @return array<string, int> Y-m-d => points
     */
    public function dailyPointsBetween(User $user, string $from, string $to): array
    {
        return Point::where('user_id', $user->id)->where('category', 'daily')
            ->where('day', '>=', $from)->where('day', '<=', $to)
            ->get()->groupBy(fn (Point $p) => $p->day)
            ->map(fn (Collection $points) => (int) $points->sum('points'))->all();
    }
}
