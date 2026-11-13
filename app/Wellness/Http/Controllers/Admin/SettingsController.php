<?php

namespace App\Wellness\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

/** Module-wide settings: on/off, the official start date, the grace period and testing options. */
class SettingsController extends Controller
{
    public function edit()
    {
        return view('wellness.admin.settings', [
            'enabled' => Setting::get('wellness_enabled') === '1',
            'officialStart' => Setting::get('wellness_official_start'),
            'grace' => Setting::get('wellness_grace_days'),
            'manualDevice' => Setting::get('wellness_manual_device') === '1',
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'wellness_official_start' => 'required|date',
            'wellness_grace_days' => 'required|integer|min:0|max:14',
        ]);
        Setting::put('wellness_enabled', $request->boolean('wellness_enabled') ? '1' : '0');
        Setting::put('wellness_official_start', \Carbon\Carbon::parse($data['wellness_official_start'])->toDateString());
        Setting::put('wellness_grace_days', (string) $data['wellness_grace_days']);
        Setting::put('wellness_manual_device', $request->boolean('wellness_manual_device') ? '1' : '0');

        return back()->with('status', 'Wellness settings saved.');
    }
}
