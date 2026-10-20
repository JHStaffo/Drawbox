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
        <label for="reminder_days_before">Reminder email (days before expiry)</label>
        <input id="reminder_days_before" type="number" min="0" max="30" name="reminder_days_before" value="{{ $reminderDaysBefore }}" required>
        <div class="hint">Set to 0 to turn reminders off.</div>
    </div>
    <button class="btn" type="submit">Save settings</button>
</form>
@endsection
