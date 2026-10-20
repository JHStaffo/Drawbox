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
            'reminderDaysBefore' => Setting::get('reminder_days_before'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'draw_window_days' => 'required|integer|min:1|max:365',
            'reminder_days_before' => 'required|integer|min:0|max:30',
        ]);

        foreach ($data as $key => $value) {
            Setting::put($key, (string) $value);
        }

        return back()->with('status', 'Settings saved. The draw window applies to calls credited from now on.');
    }
}
