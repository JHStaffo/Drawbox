@extends('layouts.app', ['title' => 'Scope Check'])

@section('band')
    <h1>Scope Check</h1>
    <p>Check the level of care a call needs from the equipment and meds requested, and how many crew members and trucks it needs to lift the patient. This is a guide: when something doesn't fit, ask a supervisor.</p>
@endsection

@section('content')
<div class="stack">
    @if (! $hasRules)
        <div class="card"><p class="empty">No equipment, meds or lift assist rules have been set up yet. @if (auth()->user()->hasRole('admin'))Add them in <a href="{{ route('supervisor.settings') }}">Settings</a>.@else Ask an admin to add them.@endif</p></div>
    @endif

    @if ($result)
        <div class="card scope-result">
            <div class="scope-answer">
                <div>
                    <div class="hint">Level of care</div>
                    @if ($result['level'])
                        <div class="scope-big scope-{{ $result['level'] }}">{{ \App\Models\ScopeItem::LEVELS[$result['level']] }}</div>
                        <div class="hint">Because of: {{ $result['drivers']->pluck('name')->join(', ') }}</div>
                    @else
                        <div class="scope-big scope-bls">BLS</div>
                        <div class="hint">No equipment or meds were checked.</div>
                    @endif
                </div>
                <div>
                    <div class="hint">Lift assist</div>
                    <div class="scope-big">{{ $result['crew'] }} crew · {{ $result['trucks'] }} {{ Str::plural('truck', $result['trucks']) }}</div>
                    <div class="hint">
                        @if ($result['rules']->isEmpty())
                            No lift assist rule applies.
                        @else
                            @foreach ($result['rules'] as $rule){{ $rule->conditions() }}: {{ $rule->crew }} crew, {{ $rule->trucks }} {{ Str::plural('truck', $rule->trucks) }}@if ($rule->note) ({{ $rule->note }})@endif<br>@endforeach
                        @endif
                    </div>
                </div>
            </div>
            @if ($result['items']->contains(fn ($i) => $i->note))
                <ul class="hint mt" style="margin-bottom:0">
                    @foreach ($result['items']->filter(fn ($i) => $i->note) as $i)<li>{{ $i->name }}: {{ $i->note }}</li>@endforeach
                </ul>
            @endif
        </div>
    @endif

    <form class="card" method="get" action="{{ route('scope') }}">
        <input type="hidden" name="check" value="1">
        @php($picked = collect($input['items'] ?? [])->map(fn ($id) => (int) $id))
        <div class="field">
            <label for="scope-filter">Equipment and meds requested</label>
            <input id="scope-filter" type="search" placeholder="Type to filter the list" autocomplete="off">
        </div>
        <div class="scope-list">
            @forelse ($items as $item)
                <label class="check scope-item" data-name="{{ Str::lower($item->name) }}">
                    <input type="checkbox" name="items[]" value="{{ $item->id }}" @checked($picked->contains($item->id))>
                    {{ $item->name }}@if ($result) <span class="pill scope-{{ $item->level }}">{{ $item->levelLabel() }}</span>@endif
                </label>
            @empty
                <p class="hint">None listed yet.</p>
            @endforelse
        </div>

        <h3 class="mt">Lift assist</h3>
        <div class="field-row">
            @if ($scenes)
                <div class="field"><label for="scene">Scene type</label>
                    <select id="scene" name="scene"><option value="">Not specified</option>@foreach ($scenes as $s)<option @selected(($input['scene'] ?? '') === $s)>{{ $s }}</option>@endforeach</select>
                </div>
            @endif
            @if ($destinations)
                <div class="field"><label for="destination">Destination type</label>
                    <select id="destination" name="destination"><option value="">Not specified</option>@foreach ($destinations as $d)<option @selected(($input['destination'] ?? '') === $d)>{{ $d }}</option>@endforeach</select>
                </div>
            @endif
            <div class="field"><label for="steps">Number of steps</label><input id="steps" type="number" min="0" max="500" name="steps" value="{{ $input['steps'] ?? '' }}"></div>
            <div class="field"><label for="weight">Patient weight (lbs)</label><input id="weight" type="number" min="0" max="2000" name="weight" value="{{ $input['weight'] ?? '' }}"></div>
        </div>
        <div class="row-actions">
            <button class="btn" type="submit">Check</button>
            <a class="btn btn-outline" href="{{ route('scope') }}">Clear</a>
            @if (auth()->user()->hasRole('admin'))<a class="btn btn-outline" href="{{ route('supervisor.settings') }}#scope">Edit rules</a>@endif
        </div>
    </form>
</div>
<script>
    document.getElementById('scope-filter').addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        document.querySelectorAll('.scope-item').forEach(function (el) {
            el.style.display = !q || el.dataset.name.indexOf(q) !== -1 ? '' : 'none';
        });
    });
</script>
@endsection
