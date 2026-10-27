<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit()
    {
        return view('supervisor.settings', [
            'drawWindowDays' => Setting::get('draw_window_days'),
            'lockerPickupDays' => Setting::get('locker_pickup_days'),
            'expiryReminderEnabled' => (bool) Setting::get('expiry_reminder_enabled'),
            'newsHide' => collect(['dispatcher', 'supervisor', 'admin'])->mapWithKeys(fn ($r) => [$r => (bool) Setting::get("news_hide_{$r}")]),
            'newsMinDollars' => (int) Setting::get('news_min_cents') / 100,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'draw_window_days' => 'required|integer|min:1|max:365',
            'expiry_reminder_enabled' => 'boolean',
            'locker_pickup_days' => 'required|integer|min:1|max:90',
            'news_hide_dispatcher' => 'boolean',
            'news_hide_supervisor' => 'boolean',
            'news_hide_admin' => 'boolean',
            'news_min_dollars' => 'nullable|numeric|min:0|max:100000',
        ]);

        Setting::put('draw_window_days', (string) $data['draw_window_days']);
        Setting::put('locker_pickup_days', (string) $data['locker_pickup_days']);
        Setting::put('expiry_reminder_enabled', $request->boolean('expiry_reminder_enabled') ? '1' : '0');
        foreach (['dispatcher', 'supervisor', 'admin'] as $role) {
            Setting::put("news_hide_{$role}", $request->boolean("news_hide_{$role}") ? '1' : '0');
        }
        Setting::put('news_min_cents', (string) (int) round(($data['news_min_dollars'] ?? 0) * 100));

        return back()->with('status', 'Settings saved. The draw window applies to calls credited from now on.');
    }
}
