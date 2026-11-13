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
        @foreach ($profiles as $profile)
            <input type="hidden" name="news_hide[{{ $profile->id }}]" value="0">
            <label class="check"><input type="checkbox" name="news_hide[{{ $profile->id }}]" value="1" @checked($profile->hide_winners)> Don't display {{ $profile->label() }} winners</label>
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
<form class="card" id="anthropic" method="post" action="{{ route('supervisor.settings.anthropic') }}" style="max-width:640px">@csrf @method('put')
    <h2>Protocol import: Anthropic API key</h2>
    <p class="hint">Protocols &gt; Build protocols &gt; Import uses Claude to read uploaded PDFs and photos. Create a key at console.anthropic.com under API keys and paste it here. It's checked with Anthropic before it's saved, and stored encrypted.</p>
    @if ($anthropicKey->source() === 'settings')
        <p><span class="pill pill-green">Saved</span> {{ $anthropicKey->masked() }}</p>
    @elseif ($anthropicKey->source() === 'env')
        <p><span class="pill pill-green">From the server's .env file</span> {{ $anthropicKey->masked() }}. A key saved here is used instead.</p>
    @else
        <p><span class="pill">Not set</span> Protocol import is off until a key is added.</p>
    @endif
    <div class="field">
        <label for="anthropic_api_key">{{ $anthropicKey->source() ? 'Replace with a new key' : 'API key' }}</label>
        <input id="anthropic_api_key" type="password" name="anthropic_api_key" placeholder="sk-ant-..." autocomplete="off" required maxlength="300">
        @error('anthropic_api_key')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div class="row-actions">
        <button class="btn" type="submit">Check and save</button>
        @if ($anthropicKey->source() === 'settings')
            <button class="btn btn-outline" type="submit" form="anthropic-remove">Remove key</button>
        @endif
    </div>
</form>
@if ($anthropicKey->source() === 'settings')
    <form id="anthropic-remove" method="post" action="{{ route('supervisor.settings.anthropic.destroy') }}" onsubmit="return confirm('Remove the Anthropic API key? Protocol import stops until a key is added again.')">@csrf @method('delete')</form>
@endif

<div class="card" id="scope">
    <h2>Scope Check: equipment and meds ({{ $scopeItems->count() }})</h2>
    <p class="hint">What dispatchers can tick on the Scope Check tab. A call needs the highest level of care of anything ticked, and the message shows with the result.</p>
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

    <div class="card" id="lift">
        <h2>Scope Check: lift assist rules ({{ $rules->count() }})</h2>
        <p class="hint">A rule applies when a call meets every condition it sets, and the call's ideal crew is the most of any rule that applies. Scene and destination work either way round, so a House and Hospital rule covers House to Hospital and Hospital to House. Leave a condition blank to ignore it. A rule needs at least one condition.</p>
        @if ($rules->isNotEmpty())
            <div class="table-wrap"><table>
                <tr><th>#</th><th>Scene</th><th>Destination</th><th class="num">Weight (lbs) at least</th><th class="num">Steps at least</th><th class="num">Ideal crew</th><th>Note</th><th></th></tr>
                @foreach ($rules as $rule)
                    <tr>
                        <td>{{ $rule->id }}</td>
                        <td><input form="r{{ $rule->id }}" type="text" name="scene_type" value="{{ $rule->scene_type }}" list="scene-list" aria-label="Scene"></td>
                        <td><input form="r{{ $rule->id }}" type="text" name="destination_type" value="{{ $rule->destination_type }}" list="destination-list" aria-label="Destination"></td>
                        <td class="num"><input form="r{{ $rule->id }}" type="number" min="1" name="min_weight_lbs" value="{{ $rule->min_weight_lbs }}" style="width:80px" aria-label="Weight"></td>
                        <td class="num"><input form="r{{ $rule->id }}" type="number" min="1" name="min_steps" value="{{ $rule->min_steps }}" style="width:70px" aria-label="Steps"></td>
                        <td class="num"><input form="r{{ $rule->id }}" type="number" min="1" name="crew" value="{{ $rule->crew }}" style="width:60px" required aria-label="Crew"></td>
                        <td><input form="r{{ $rule->id }}" type="text" name="note" value="{{ $rule->note }}" aria-label="Note"></td>
                        <td class="right" style="white-space:nowrap">
                            <form id="r{{ $rule->id }}" method="post" action="{{ route('admin.scope.rules.update', $rule) }}" style="display:inline">@csrf @method('put')<button class="btn btn-small" type="submit">Save</button></form>
                            <form method="post" action="{{ route('admin.scope.rules.destroy', $rule) }}" style="display:inline" onsubmit="return confirm('Delete lift rule #{{ $rule->id }}?')">@csrf @method('delete')<button class="btn btn-small btn-outline" type="submit">Delete</button></form>
                        </td>
                    </tr>
                @endforeach
            </table></div>
        @endif
        <h3 class="mt">Add a lift assist rule</h3>
        <form method="post" action="{{ route('admin.scope.rules.store') }}">@csrf
            <div class="field-row">
                <div class="field"><label>Scene</label><input type="text" name="scene_type" list="scene-list"></div>
                <div class="field"><label>Destination</label><input type="text" name="destination_type" list="destination-list"></div>
                <div class="field"><label>Weight (lbs) at least</label><input type="number" min="1" name="min_weight_lbs"></div>
                <div class="field"><label>Steps at least</label><input type="number" min="1" name="min_steps"></div>
            </div>
            <div class="field-row">
                <div class="field"><label>Ideal crew members</label><input type="number" min="1" name="crew" value="3" required></div>
                <div class="field"><label>Note</label><input type="text" name="note"></div>
            </div>
            <button class="btn" type="submit">Add rule</button>
        </form>
        <datalist id="scene-list">@foreach (\App\Services\ScopeCheck::options('scope_scene_types') as $o)<option value="{{ $o }}">@endforeach</datalist>
        <datalist id="destination-list">@foreach (\App\Services\ScopeCheck::options('scope_destination_types') as $o)<option value="{{ $o }}">@endforeach</datalist>
    </div>

    <form class="card" method="post" action="{{ route('admin.scope.options') }}" style="max-width:640px">@csrf @method('put')
        <h2>Scope Check: options</h2>
        <div class="field-row">
            <div class="field"><label for="scope_base_crew">Usual ideal crew per call</label><input id="scope_base_crew" type="number" min="1" name="scope_base_crew" value="{{ $baseCrew }}" required></div>
        </div>
        <p class="hint">What a call needs when no lift assist rule applies.</p>
        <div class="field-row">
            <div class="field"><label for="scope_scene_types">Scene types (one per line)</label><textarea id="scope_scene_types" name="scope_scene_types" rows="7">{{ $sceneTypes }}</textarea></div>
            <div class="field"><label for="scope_destination_types">Destination types (one per line)</label><textarea id="scope_destination_types" name="scope_destination_types" rows="7">{{ $destinationTypes }}</textarea></div>
        </div>
        <p class="hint">These are the choices dispatchers pick from. A lift rule's scene or destination must match one of them exactly.</p>
        <button class="btn" type="submit">Save options</button>
    </form>
</div>
@endsection
