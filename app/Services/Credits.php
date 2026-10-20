<?php

namespace App\Services;

use App\Models\Call;
use App\Models\DrawCredit;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\DrawCredited;
use App\Notifications\DrawsExpiringSoon;
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

    /**
     * Email each employee once about all their draws that expire within the
     * next 24 hours. Returns the number of emails sent.
     */
    public function sendReminders(): int
    {
        if (! Setting::get('expiry_reminder_enabled')) {
            return 0;
        }

        $byUser = DrawCredit::available()
            ->whereNull('reminded_at')
            ->where('expires_at', '<=', now()->addDay())
            ->with('user', 'call')->get()
            ->groupBy('user_id');

        foreach ($byUser as $credits) {
            $credits->first()->user->notify(new DrawsExpiringSoon($credits));
            DrawCredit::whereKey($credits->modelKeys())->update(['reminded_at' => now()]);
        }

        return $byUser->count();
    }
}
