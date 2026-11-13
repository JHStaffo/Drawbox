<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\LiftRule;
use App\Models\PermissionProfile;
use App\Models\ProtocolCategory;
use App\Models\ProtocolVersion;
use App\Models\ScopeItem;
use App\Models\Setting;
use App\Services\ProtocolImport\AnthropicKey;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit()
    {
        return view('supervisor.settings', [
            'drawWindowDays' => Setting::get('draw_window_days'),
            'rules' => LiftRule::orderBy('id')->get(),
            'baseCrew' => Setting::get('scope_base_crew'),
            'sceneTypes' => Setting::get('scope_scene_types'),
            'destinationTypes' => Setting::get('scope_destination_types'),
            'scopeItems' => ScopeItem::orderBy('name')->get()->sortByDesc(fn ($i) => $i->rank())->values(),
            'drawDelayHours' => Setting::get('draw_delay_hours') + 0,
            'lockerPickupDays' => Setting::get('locker_pickup_days'),
            'expiryReminderEnabled' => (bool) Setting::get('expiry_reminder_enabled'),
            'profiles' => PermissionProfile::ordered(),
            'newsMinDollars' => (int) Setting::get('news_min_cents') / 100,
            'protocolCategories' => ProtocolCategory::ordered(),
            'usedCategories' => ProtocolVersion::whereNotNull('protocol_category_id')->distinct()->pluck('protocol_category_id')->all(),
            'anthropicKey' => app(AnthropicKey::class),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'draw_window_days' => 'required|integer|min:1|max:365',
            'draw_delay_hours' => 'nullable|numeric|min:0|max:72',
            'expiry_reminder_enabled' => 'boolean',
            'locker_pickup_days' => 'required|integer|min:1|max:90',
            'news_hide' => 'array',
            'news_min_dollars' => 'nullable|numeric|min:0|max:100000',
        ]);

        Setting::put('draw_window_days', (string) $data['draw_window_days']);
        if (isset($data['draw_delay_hours'])) {
            Setting::put('draw_delay_hours', (string) ($data['draw_delay_hours'] + 0));
        }
        Setting::put('locker_pickup_days', (string) $data['locker_pickup_days']);
        Setting::put('expiry_reminder_enabled', $request->boolean('expiry_reminder_enabled') ? '1' : '0');
        foreach (PermissionProfile::all() as $profile) {
            $profile->update(['hide_winners' => (bool) ($data['news_hide'][$profile->id] ?? false)]);
        }
        Setting::put('news_min_cents', (string) (int) round(($data['news_min_dollars'] ?? 0) * 100));

        return back()->with('status', 'Settings saved. The draw window applies to calls credited from now on.');
    }

    /** Saves the Anthropic API key for protocol import, after checking it works. */
    public function updateAnthropic(Request $request, AnthropicKey $keys)
    {
        $data = $request->validate([
            'anthropic_api_key' => ['required', 'string', 'max:300', 'regex:/^sk-ant-[A-Za-z0-9_-]+$/'],
        ], ['anthropic_api_key.regex' => 'That isn\'t an Anthropic API key. It starts with sk-ant- and is on console.anthropic.com under API keys.']);
        $key = trim($data['anthropic_api_key']);
        try {
            $keys->check($key);
        } catch (\RuntimeException $e) {
            return redirect(route('supervisor.settings').'#anthropic')->withErrors(['anthropic_api_key' => $e->getMessage()]);
        }
        $had = $keys->source() === 'settings';
        $keys->save($key);
        audit_note(($had ? 'Replaced' : 'Saved').' the Anthropic API key for protocol import (now '.$keys->masked().'). The key itself is stored encrypted and never logged.');

        return redirect(route('supervisor.settings').'#anthropic')->with('status', 'Saved the Anthropic API key. Protocol import is ready.');
    }

    public function destroyAnthropic(AnthropicKey $keys)
    {
        $was = $keys->masked();
        $keys->save(null);
        audit_note("Removed the Anthropic API key ({$was}) from Settings.".($keys->source() === 'env' ? ' The key in .env is used instead.' : ' Protocol import is off until a key is added.'));

        return redirect(route('supervisor.settings').'#anthropic')->with('status', 'Removed the Anthropic API key.');
    }
}
