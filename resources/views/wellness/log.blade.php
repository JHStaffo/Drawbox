@extends('layouts.app', ['title' => 'Log a day'])

@php($manualValue = fn ($metric) => old($metric, isset($manual[$metric]) ? $manual[$metric]->value + 0 : ''))

@section('band')
    <h1>{{ $date->isToday() ? 'Log today' : 'Log '.$date->format('l, F j') }}</h1>
    <p>{{ $day['points'] }} of {{ $day['max'] }} daily points @if ($day['test_period'])· Test period @endif</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <div class="row-actions">
            <a class="btn btn-outline btn-small" href="{{ route('wellness.log', $week[0]->subDays(7)->toDateString()) }}">‹ Week before</a>
            @foreach ($week as $d)
                @if ($d->isFuture())
                    <span class="btn btn-outline btn-small" style="opacity:.4">{{ $d->format('D j') }}</span>
                @else
                    <a href="{{ route('wellness.log', $d->toDateString()) }}" @class(['btn btn-small', 'btn-outline' => ! $d->isSameDay($date), 'btn-teal' => $d->isSameDay($date)])>{{ $d->format('D j') }}</a>
                @endif
            @endforeach
            @if ($week[6]->lt(today()))
                <a class="btn btn-outline btn-small" href="{{ route('wellness.log', $week[6]->addDay()->toDateString()) }}">Week after ›</a>
            @endif
        </div>
    </div>

    <div class="grid grid-2">
        <form class="card" method="post" action="{{ route('wellness.log.save', $date->toDateString()) }}">@csrf @method('put')
            <h2>What you did</h2>
            @if (! $open)
                <p class="flash flash-error">This week is closed, so it can't be changed here. If something is missing, ask a wellness administrator.</p>
            @endif
            <fieldset class="plain-fieldset" @disabled(! $open)>
                <div class="field-row">
                    <div class="field">
                        <label for="meals">Meals recorded</label>
                        <select id="meals" name="meals">
                            <option value="">None</option>
                            @foreach ([1, 2, 3] as $n)<option value="{{ $n }}" @selected((string) $manualValue('meals') === (string) $n)>{{ $n }}</option>@endforeach
                        </select>
                        <div class="hint">Meals you tracked in your food log</div>
                    </div>
                    <div class="field">
                        <label for="water_oz">Water (oz)</label>
                        <input id="water_oz" type="number" name="water_oz" min="0" max="400" step="1" value="{{ $manualValue('water_oz') }}" inputmode="numeric">
                        <div class="hint">Goal: 64 oz</div>
                    </div>
                </div>
                <div class="field">
                    <label class="check"><input type="checkbox" name="meal_plan" value="1" @checked(old('meal_plan', isset($manual['meal_plan']) && $manual['meal_plan']->value >= 1))> I followed my meal plan today</label>
                </div>

                @if ($canTypeDevice)
                    <h3 class="mt">From your wearable</h3>
                    <p class="hint">Normally these come from your Apple Watch or Fitbit. You can type them in for now. If your device also sends a value, the higher one counts, never both.</p>
                    <div class="field-row">
                        <div class="field">
                            <label for="steps">Steps</label>
                            <input id="steps" type="number" name="steps" min="0" max="150000" value="{{ $manualValue('steps') }}" inputmode="numeric">
                        </div>
                        <div class="field">
                            <label for="sleep_hours">Sleep (hours)</label>
                            <input id="sleep_hours" type="number" name="sleep_hours" min="0" max="24" step="0.25" value="{{ old('sleep_hours', isset($manual['sleep_minutes']) ? round($manual['sleep_minutes']->value / 60, 2) : '') }}" inputmode="decimal">
                        </div>
                        <div class="field">
                            <label for="exercise_minutes">Workout (minutes)</label>
                            <input id="exercise_minutes" type="number" name="exercise_minutes" min="0" max="1440" value="{{ $manualValue('exercise_minutes') }}" inputmode="numeric">
                        </div>
                    </div>
                @else
                    <p class="hint mt">Steps, sleep and workouts come from your wearable. If it isn't working, ask a wellness administrator for an exception.</p>
                @endif
                <button class="btn" type="submit">Save</button>
            </fieldset>
        </form>

        <div class="card">
            <h2>Points for {{ $date->isToday() ? 'today' : $date->format('D, M j') }}</h2>
            @include('wellness.partials.day-habits', ['day' => $day])
            @if ($day['adjustments'])
                <p class="hint mt">Includes {{ $day['adjustments'] > 0 ? '+' : '' }}{{ $day['adjustments'] }} from an administrator adjustment.</p>
            @endif
            @if ($others->isNotEmpty())
                <h3 class="mt">From devices</h3>
                <ul class="plain-list">
                    @foreach ($others as $metric => $readings)
                        @foreach ($readings as $r)
                            <li>{{ \App\Wellness\Models\Reading::METRICS[$metric][0] }}: {{ \App\Wellness\Wellness::show($metric, $r->value) }} <span class="hint">· {{ $r->sourceLabel() }}{{ $r->device ? ', '.$r->device : '' }} · {{ $r->updated_at->diffForHumans() }}</span></li>
                        @endforeach
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
@endsection
