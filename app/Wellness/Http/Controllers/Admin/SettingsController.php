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
            'submitDays' => Setting::get('wellness_submit_days'),
            'rewardDollars' => (int) Setting::get('wellness_weekly_reward_cents') / 100,
            'brands' => Setting::get('wellness_gift_brands'),
            'inbodyDays' => Setting::get('wellness_inbody_min_days'),
            'inbodyFat' => Setting::get('wellness_inbody_fat_drop'),
            'inbodyMuscle' => Setting::get('wellness_inbody_muscle_gain'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'wellness_official_start' => 'required|date',
            'wellness_grace_days' => 'required|integer|min:0|max:14',
            'wellness_submit_days' => 'sometimes|required|integer|min:1|max:366',
            'reward_dollars' => 'required_with:wellness_submit_days|numeric|min:0|max:1000',
            'wellness_gift_brands' => 'required_with:wellness_submit_days|string|max:2000',
            'wellness_inbody_min_days' => 'required_with:wellness_submit_days|integer|min:0|max:365',
            'wellness_inbody_fat_drop' => 'required_with:wellness_submit_days|numeric|min:0|max:20',
            'wellness_inbody_muscle_gain' => 'required_with:wellness_submit_days|numeric|min:0|max:50',
        ]);
        Setting::put('wellness_enabled', $request->boolean('wellness_enabled') ? '1' : '0');
        Setting::put('wellness_official_start', \Carbon\Carbon::parse($data['wellness_official_start'])->toDateString());
        Setting::put('wellness_grace_days', (string) $data['wellness_grace_days']);
        Setting::put('wellness_manual_device', $request->boolean('wellness_manual_device') ? '1' : '0');
        // The page always sends these; older forms or API callers may leave them out.
        if (isset($data['wellness_submit_days'])) {
            $brands = collect(preg_split('/\r?\n/', $data['wellness_gift_brands']))->map(fn ($b) => trim($b))->filter()->unique()->implode("\n");
            Setting::put('wellness_submit_days', (string) $data['wellness_submit_days']);
            Setting::put('wellness_weekly_reward_cents', (string) (int) round($data['reward_dollars'] * 100));
            Setting::put('wellness_gift_brands', $brands);
            Setting::put('wellness_inbody_min_days', (string) $data['wellness_inbody_min_days']);
            Setting::put('wellness_inbody_fat_drop', (string) ($data['wellness_inbody_fat_drop'] + 0));
            Setting::put('wellness_inbody_muscle_gain', (string) ($data['wellness_inbody_muscle_gain'] + 0));
        }

        return back()->with('status', 'Wellness settings saved.');
    }
}
