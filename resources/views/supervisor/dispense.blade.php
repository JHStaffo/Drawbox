@extends('layouts.app', ['title' => 'Dispense prizes'])

@section('band')
    <h1>Dispense {{ $employee->name }}'s prizes</h1>
    <p>{{ $draws->count() }} {{ Str::plural('prize', $draws->count()) }} worth {{ money($draws->sum('value_cents')) }}.</p>
@endsection

@section('content')
<form class="stack" method="post" action="{{ route('supervisor.dispense.store', $employee) }}" id="dispense-form">@csrf
    <div class="card">
        <h2>Prizes</h2>
        <ul class="plain-list">
            @foreach ($draws as $draw)
                <li>{{ $draw->label() }} <span class="hint">· {{ money($draw->value_cents) }}</span><input type="hidden" name="draws[]" value="{{ $draw->id }}"></li>
            @endforeach
        </ul>
    </div>

    <div class="card">
        <h2>How are they being handed out?</h2>
        <div class="choice-row">
            <label class="choice"><input type="radio" name="method" value="direct" @checked(old('method', 'direct') === 'direct')> <span><strong>Directly to {{ explode(' ', $employee->name)[0] }}</strong><br><span class="hint">They count as received now.</span></span></label>
            <label class="choice"><input type="radio" name="method" value="locker" @checked(old('method') === 'locker')> <span><strong>In a locker</strong><br><span class="hint">They count as received when {{ explode(' ', $employee->name)[0] }} presses Picked Up. They have {{ $pickupDays }} {{ Str::plural('day', $pickupDays) }}.</span></span></label>
        </div>
    </div>

    <div class="card" id="locker-step">
        <h2>Which office?</h2>
        <div class="choice-row">
            @foreach ($offices as $key => $office)
                <label class="choice"><input type="radio" name="office" value="{{ $key }}" @checked(old('office') === $key)> <span><strong>{{ $office['name'] }}</strong><br><span class="hint">{{ $office['lockers']->filter(fn ($l) => ! $l->current)->count() }} of {{ $office['lockers']->count() }} lockers open</span></span></label>
            @endforeach
        </div>

        @foreach ($offices as $key => $office)
            <div class="office-map mt" data-office="{{ $key }}">
                <h3>Pick a locker at {{ $office['name'] }}</h3>
                <div class="table-wrap">@include('partials.locker-bank', ['office' => $office, 'pick' => true])</div>
            </div>
        @endforeach

        <div class="field mt" id="combo-field">
            <label for="combo-1">Lock combination</label>
            <div class="combo">
                @foreach ([0, 1, 2] as $i)
                    <input id="combo-{{ $i + 1 }}" type="number" inputmode="numeric" min="0" max="999" name="combination[]" value="{{ old('combination.'.$i) }}" aria-label="Number {{ $i + 1 }}">
                @endforeach
            </div>
            <div class="hint">{{ explode(' ', $employee->name)[0] }} gets this in an email and on their Home page.</div>
        </div>
    </div>

    <div class="row-actions">
        <button class="btn" type="submit">Dispense</button>
        <a class="btn btn-outline" href="{{ route('supervisor.dashboard') }}">Cancel</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    const form = document.getElementById('dispense-form');
    const lockerStep = document.getElementById('locker-step');

    function update() {
        const method = form.querySelector('input[name=method]:checked');
        const office = form.querySelector('input[name=office]:checked');
        const inLocker = method && method.value === 'locker';
        lockerStep.hidden = !inLocker;
        form.querySelectorAll('.office-map').forEach(function (map) {
            const show = inLocker && office && map.dataset.office === office.value;
            map.hidden = !show;
            map.querySelectorAll('input').forEach(function (i) { if (!show) i.checked = false; });
        });
        document.getElementById('combo-field').hidden = !(inLocker && office);
        form.querySelectorAll('input[name="combination[]"]').forEach(function (i) { i.required = inLocker; });
    }

    form.addEventListener('change', update);
    update();
})();
</script>
@endpush
