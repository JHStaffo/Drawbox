<?php

namespace App\Wellness;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\WellnessRewardEarned;
use App\Wellness\Models\Award;
use App\Wellness\Models\Point;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The weekly $10 gift card. A week is checked once it has closed (the grace
 * period after Saturday), so late syncs count. Only daily-habit points
 * count toward the 56; other activities never make up for missing days.
 */
class Rewards
{
    /** Closed weeks looked at on each run, so a late adjustment still earns the reward. */
    private const WEEKS_BACK = 8;

    /** @return array<int, string> */
    public static function brands(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", Setting::get('wellness_gift_brands')))));
    }

    /**
     * Create the reward for everyone who reached the weekly target in a
     * closed week. Safe to run any number of times.
     *
     * @return int rewards created
     */
    public function closeWeeks(): int
    {
        if (! Wellness::enabled()) {
            return 0;
        }
        $created = 0;
        $target = Scoring::weeklyTarget();
        $cents = (int) Setting::get('wellness_weekly_reward_cents');
        $week = Calendar::weekStart(Calendar::today()->subDays(7));
        for ($i = 0; $i < self::WEEKS_BACK; $i++, $week = $week->subDays(7)) {
            if (! Calendar::isLocked($week)) {
                continue;
            }
            $end = $week->addDays(6);
            $totals = Point::where('category', 'daily')
                ->where('day', '>=', $week->toDateString())->where('day', '<=', $end->toDateString())
                ->selectRaw('user_id, SUM(points) as total')->groupBy('user_id')->havingRaw('SUM(points) >= ?', [$target])
                ->pluck('total', 'user_id');
            foreach ($totals as $userId => $total) {
                $exists = Award::where('user_id', $userId)->where('kind', 'weekly')->whereDate('period_start', $week->toDateString())->exists();
                if ($exists) {
                    continue;
                }
                $award = Award::create([
                    'user_id' => $userId, 'kind' => 'weekly', 'period_start' => $week->toDateString(), 'period_end' => $end->toDateString(),
                    'points' => (int) $total, 'value_cents' => $cents, 'test_period' => Calendar::isTestDay($week),
                ]);
                $created++;
                $this->tell($award);
            }
        }

        return $created;
    }

    private function tell(Award $award): void
    {
        try {
            $award->user?->notify(new WellnessRewardEarned($award));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function chooseBrand(Award $award, User $user, string $brand): void
    {
        if ($award->user_id !== $user->id) {
            abort(403);
        }
        if ($award->status !== 'pending') {
            throw ValidationException::withMessages(['brand' => 'That reward was already '.strtolower($award->statusLabel()).'.']);
        }
        if (! in_array($brand, self::brands(), true)) {
            throw ValidationException::withMessages(['brand' => 'Pick one of the gift cards on the list.']);
        }
        $award->update(['brand' => $brand, 'brand_chosen_at' => now()]);
    }

    public function markSent(Award $award, User $by, ?string $note = null): void
    {
        if ($award->status !== 'pending') {
            throw ValidationException::withMessages(['award' => 'That reward was already '.strtolower($award->statusLabel()).'.']);
        }
        $award->update(['status' => 'sent', 'sent_at' => now(), 'sent_by' => $by->id, 'status_note' => $note]);
    }

    public function void(Award $award, User $by, string $reason): void
    {
        if ($award->status !== 'pending') {
            throw ValidationException::withMessages(['award' => 'Only a reward that hasn\'t been sent can be voided.']);
        }
        $award->update(['status' => 'void', 'sent_by' => $by->id, 'status_note' => $reason]);
    }
}
