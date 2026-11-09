@extends('layouts.app', ['title' => 'Settings'])

@section('band')
    <h1>Settings</h1>
@endsection

@section('content')
<div class="stack">
<form class="card" method="post" action="{{ route('supervisor.settings.update') }}" style="max-width:520px">@csrf @method('put')
    <div class="field">
        <label for="draw_window_days">Draw window (days)</label>
        <input id="draw_window_days" type="number" min="1" max="365" name="draw_window_days" value="{{ $drawWindowDays }}" required>
        <div class="hint">How long a crew member has to draw after dispatch credits a call. Changes apply to calls credited from now on.</div>
    </div>
    <div class="field">
        <label for="draw_delay_hours">Wait before drawing (hours)</label>
        <input id="draw_delay_hours" type="number" min="0" max="72" step="0.25" name="draw_delay_hours" value="{{ $drawDelayHours }}" required style="max-width:140px">
        <div class="hint">How long after dispatch credits a call before the crew member can draw. 0 means right away. Changes apply to calls credited from now on.</div>
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

@php($levels = \App\Models\ScopeItem::LEVELS)
<div class="card" id="scope">
    <h2>Scope Check: equipment and meds ({{ $scopeItems->count() }})</h2>
    <p class="hint">What dispatchers can tick on the Scope Check tab. A call needs the highest level of care of anything ticked, and the message shows with the result. Lift assist rules are on their <a href="{{ route('admin.scope') }}">own page</a>.</p>
    @if ($scopeItems->isNotEmpty())
        <div class="table-wrap"><table>
            <tr><th>Equipment or med</th><th>Level of care</th><th>Message to display (optional)</th><th></th></tr>
            @foreach ($scopeItems as $item)
                <tr>
                    <td><input form="si{{ $item->id }}" type="text" name="name" value="{{ $item->name }}" required aria-label="Equipment or med"></td>
                    <td><select form="si{{ $item->id }}" name="level" aria-label="Level of care">@foreach ($levels as $k => $l)<option value="{{ $k }}" @selected($item->level === $k)>{{ $l }}</option>@endforeach</select></td>
                    <td><input form="si{{ $item->id }}" type="text" name="note" value="{{ $item->note }}" aria-label="Message"></td>
                    <td class="right" style="white-space:nowrap">
                        <form id="si{{ $item->id }}" method="post" action="{{ route('admin.scope.items.update', $item) }}" style="display:inline">@csrf @method('put')<button class="btn btn-small" type="submit">Save</button></form>
                        <form method="post" action="{{ route('admin.scope.items.destroy', $item) }}" style="display:inline" onsubmit="return confirm('Delete {{ addslashes($item->name) }}?')">@csrf @method('delete')<button class="btn btn-small btn-outline" type="submit">Delete</button></form>
                    </td>
                </tr>
            @endforeach
        </table></div>
    @endif
    <form method="post" action="{{ route('admin.scope.items.store') }}" class="mt">@csrf
        <div class="field-row">
            <div class="field"><label for="new-scope-name">Equipment or med</label><input id="new-scope-name" type="text" name="name" required></div>
            <div class="field"><label for="new-scope-level">Level of care</label><select id="new-scope-level" name="level">@foreach ($levels as $k => $l)<option value="{{ $k }}" @selected($k === 'als')>{{ $l }}</option>@endforeach</select></div>
            <div class="field"><label for="new-scope-note">Message to display (optional)</label><input id="new-scope-note" type="text" name="note"></div>
        </div>
        <button class="btn" type="submit">Add</button>
    </form>
</div>
</div>
@endsection
