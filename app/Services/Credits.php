<?php

namespace App\Services;

use App\Models\Call;
use App\Models\DrawCredit;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\DrawCredited;
use App\Notifications\DrawExpiringSoon;
use Illuminate\Support\Facades\DB;

class Credits
{
    /** Record a solicited call and give each crew member a draw. */
    public function creditCall(array $call, array $crewIds, User $by): Call
    {
        $call = DB::transaction(function () use ($call, $crewIds, $by) {
            $call = Call::create($call + ['created_by' => $by->id]);
            $expires = now()->addDays((int) Setting::get('draw_window_days'))->endOfDay();

            foreach (array_unique($crewIds) as $userId) {
                $call->credits()->create(['user_id' => $userId, 'expires_at' => $expires]);
            }

            return $call;
        });

        foreach ($call->credits()->with('user')->get() as $credit) {
            $credit->user->notify(new DrawCredited($credit));
        }

        return $call;
    }

    public function expireOverdue(): int
    {
        return DrawCredit::where('status', 'open')->where('expires_at', '<=', now())->update(['status' => 'expired']);
    }

    public function sendReminders(): int
    {
        $days = (int) Setting::get('reminder_days_before');
        if ($days <= 0) {
            return 0;
        }

        $credits = DrawCredit::available()
            ->whereNull('reminded_at')
            ->where('expires_at', '<=', now()->addDays($days))
            ->with('user', 'call')->get();

        foreach ($credits as $credit) {
            $credit->user->notify(new DrawExpiringSoon($credit));
            $credit->update(['reminded_at' => now()]);
        }

        return $credits->count();
    }
}
