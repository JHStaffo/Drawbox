@extends('layouts.app', ['title' => 'Wellness settings'])

@section('band')
    <h1>Wellness settings</h1>
@endsection

@section('content')
<form class="card" method="post" action="{{ route('wellness.admin.settings.update') }}" style="max-width:560px">@csrf @method('put')
    <div class="field">
        <input type="hidden" name="wellness_enabled" value="0">
        <label class="check"><input type="checkbox" name="wellness_enabled" value="1" @checked($enabled)> Wellness module is on</label>
        <div class="hint">Turning it off hides the Wellness menu, its pages and its API for everyone. Nothing is deleted, and the rest of Pulse is unaffected.</div>
    </div>
    <div class="field">
        <label for="wellness_official_start">Official start date</label>
        <input id="wellness_official_start" type="date" name="wellness_official_start" value="{{ $officialStart }}" required style="max-width:200px">
        <div class="hint">Points before this date are the test period and don't count toward prizes.</div>
    </div>
    <div class="field">
        <label for="wellness_grace_days">Days a week stays open after it ends</label>
        <input id="wellness_grace_days" type="number" name="wellness_grace_days" value="{{ $grace }}" min="0" max="14" required style="max-width:120px">
        <div class="hint">Time for late device syncs and forgotten entries. After that, a week only changes through an admin adjustment with a reason.</div>
    </div>
    <div class="field">
        <input type="hidden" name="wellness_manual_device" value="0">
        <label class="check"><input type="checkbox" name="wellness_manual_device" value="1" @checked($manualDevice)> Anyone can type in steps, sleep and workouts</label>
        <div class="hint">Useful while wearables are being set up. When off, only Team Members with a device exception can type these in.</div>
    </div>
    <div class="field">
        <label for="wellness_submit_days">Days back an activity can be sent in</label>
        <input id="wellness_submit_days" type="number" name="wellness_submit_days" value="{{ $submitDays }}" min="1" max="366" required style="max-width:120px">
        <div class="hint">How long after a visit, InBody scan or weigh-in the Team Member has to send it.</div>
    </div>

    <h2 class="mt">Weekly reward</h2>
    <div class="field">
        <label for="reward_dollars">Gift card value ($)</label>
        <input id="reward_dollars" type="number" name="reward_dollars" value="{{ $rewardDollars }}" min="0" max="1000" step="0.01" required style="max-width:120px">
        <div class="hint">Applies to weeks closed from now on.</div>
    </div>
    <div class="field">
        <label for="wellness_gift_brands">Gift card choices (one per line)</label>
        <textarea id="wellness_gift_brands" name="wellness_gift_brands" rows="6" required>{{ $brands }}</textarea>
    </div>

    <h2 class="mt">InBody improvement</h2>
    <p class="hint">A scan earns points when, compared with the last approved scan at least this many days earlier, body fat drops or muscle rises by at least these amounts. A first scan is the starting point and earns no points.</p>
    <div class="field-row">
        <div class="field"><label for="wellness_inbody_min_days">Days between scans</label><input id="wellness_inbody_min_days" type="number" name="wellness_inbody_min_days" value="{{ $inbodyDays }}" min="0" max="365" required></div>
        <div class="field"><label for="wellness_inbody_fat_drop">Body fat down (points)</label><input id="wellness_inbody_fat_drop" type="number" step="0.1" name="wellness_inbody_fat_drop" value="{{ $inbodyFat }}" min="0" max="20" required></div>
        <div class="field"><label for="wellness_inbody_muscle_gain">Muscle up (lb)</label><input id="wellness_inbody_muscle_gain" type="number" step="0.1" name="wellness_inbody_muscle_gain" value="{{ $inbodyMuscle }}" min="0" max="50" required></div>
    </div>
    <h2 class="mt">Apple Health</h2>
    <div class="field">
        <label for="wellness_shortcut_url">Shortcut link</label>
        <input id="wellness_shortcut_url" type="url" name="wellness_shortcut_url" value="{{ $shortcutUrl }}" maxlength="500" placeholder="https://www.icloud.com/shortcuts/...">
        <div class="hint">The iCloud link you get from Share, then Copy iCloud Link, on the finished Fast Track Pulse shortcut. Team Members install it from their Devices page. Leave blank until the shortcut is ready.</div>
    </div>
    <button class="btn" type="submit">Save settings</button>
</form>
@endsection
