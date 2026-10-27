@extends('layouts.app', ['title' => 'Settings'])

@section('band')
    <h1>Settings</h1>
@endsection

@section('content')
<form class="card" method="post" action="{{ route('supervisor.settings.update') }}" style="max-width:520px">@csrf @method('put')
    <div class="field">
        <label for="draw_window_days">Draw window (days)</label>
        <input id="draw_window_days" type="number" min="1" max="365" name="draw_window_days" value="{{ $drawWindowDays }}" required>
        <div class="hint">How long a crew member has to draw after dispatch credits a call. Changes apply to calls credited from now on.</div>
    </div>
    <div class="field">
        <label for="locker_pickup_days">Locker pickup window (days)</label>
        <input id="locker_pickup_days" type="number" min="1" max="90" name="locker_pickup_days" value="{{ $lockerPickupDays }}" required>
        <div class="hint">How long a crew member has to pick up prizes left in a locker. After that, a supervisor checks the locker: if it's empty the prizes count as picked up, and if they're still there they're forfeited and go back in the box. Changes apply to prizes put in lockers from now on.</div>
    </div>
    <div class="field">
        <input type="hidden" name="expiry_reminder_enabled" value="0">
        <label class="check"><input type="checkbox" name="expiry_reminder_enabled" value="1" @checked($expiryReminderEnabled)> Email team members 24 hours before a draw expires</label>
        <div class="hint">Each team member gets one email listing all their draws that expire within 24 hours, not one email per draw.</div>
    </div>
    <h2 class="mt">Prize News</h2>
    <p class="hint">Choose which wins show in Recent Winners on everyone's Home page.</p>
    <div class="field">
        @foreach (['dispatcher' => 'Dispatcher', 'supervisor' => 'Supervisor', 'admin' => 'Admin'] as $role => $roleLabel)
            <input type="hidden" name="news_hide_{{ $role }}" value="0">
            <label class="check"><input type="checkbox" name="news_hide_{{ $role }}" value="1" @checked($newsHide[$role])> Don't display {{ $roleLabel }} winners</label>
        @endforeach
    </div>
    <div class="field">
        <label for="news_min_dollars">Don't display prizes under ($)</label>
        <input id="news_min_dollars" type="number" min="0" step="0.01" name="news_min_dollars" value="{{ $newsMinDollars ?: '' }}" placeholder="0" style="max-width:140px">
        <div class="hint">Keeps candy, snacks and other small wins out of the feed. Leave blank or 0 to show every prize.</div>
    </div>
    <button class="btn" type="submit">Save settings</button>
</form>
@endsection
