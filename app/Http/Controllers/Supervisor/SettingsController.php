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
            'expiryReminderEnabled' => (bool) Setting::get('expiry_reminder_enabled'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'draw_window_days' => 'required|integer|min:1|max:365',
            'expiry_reminder_enabled' => 'boolean',
        ]);

        Setting::put('draw_window_days', (string) $data['draw_window_days']);
        Setting::put('expiry_reminder_enabled', $request->boolean('expiry_reminder_enabled') ? '1' : '0');

        return back()->with('status', 'Settings saved. The draw window applies to calls credited from now on.');
    }
}
