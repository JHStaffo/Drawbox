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
    <button class="btn" type="submit">Save settings</button>
</form>
@endsection
