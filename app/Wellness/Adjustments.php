<?php

namespace App\Wellness;

use App\Models\User;
use App\Wellness\Models\Point;
use Illuminate\Validation\ValidationException;

/**
 * Manual point changes. Each one is a new ledger row with a reason and who
 * made it; nothing already recorded is edited, so history can't change silently.
 */
class Adjustments
{
    public function add(User $user, string $day, string $category, int $points, string $reason, User $by): Point
    {
        $date = Calendar::date($day);
        if ($date->gt(Calendar::today())) {
            throw ValidationException::withMessages(['day' => "The day can't be in the future."]);
        }
        if ($points === 0) {
            throw ValidationException::withMessages(['points' => 'Enter the points to add (or a negative number to take away).']);
        }
        if ($by->id === $user->id) {
            throw ValidationException::withMessages(['points' => "You can't adjust your own points. Another administrator has to."]);
        }
        if ($category === 'daily') {
            $current = (int) Point::where('user_id', $user->id)->where('day', $date->toDateString())->where('category', 'daily')->sum('points');
            $after = $current + $points;
            if ($after > Scoring::dailyMax() || $after < 0) {
                throw ValidationException::withMessages(['points' => "That day has {$current} daily points. Daily points have to stay between 0 and ".Scoring::dailyMax().'.']);
            }
        }

        return Point::create([
            'user_id' => $user->id, 'day' => $date->toDateString(), 'activity' => 'adjustment', 'category' => $category,
            'points' => $points, 'kind' => 'adjustment', 'reason' => $reason, 'created_by' => $by->id,
        ]);
    }
}
