@extends('layouts.app', ['title' => 'Wellness point rules'])

@section('band')
    <h1>Point rules</h1>
    <p>A change never rewrites history: it starts on the date you pick, and earlier days keep the rule they were scored under.</p>
@endsection

@section('content')
<div class="stack">
    @if ($upcoming->isNotEmpty())
        <div class="card">
            <h2>Coming up</h2>
            <ul class="plain-list">
                @foreach ($upcoming as $r)<li>{{ $r->name }}: {{ $r->points }} points{{ $r->isDaily() ? ', goal '.$r->thresholdLabel() : '' }}, {{ $r->capLabel() }}, from {{ $r->effective_from->format('M j, Y') }}</li>@endforeach
            </ul>
        </div>
    @endif
    @foreach (['daily' => 'Daily habits', 'other' => 'Other activities'] as $category => $heading)
        <div class="card">
            <h2>{{ $heading }}</h2>
            <div class="table-wrap">
            <table class="field-table">
                <thead><tr><th>Name</th><th class="num">Points</th>@if ($category === 'daily')<th class="num">Goal</th><th class="num">Most a day</th>@else<th class="num">Cap</th><th>Per</th>@endif<th>Description</th><th>Starts</th><th></th></tr></thead>
                <tbody>
                @foreach ($rules->where('category', $category) as $rule)
                    <tr>
                        <td><input form="rule-{{ $rule->key }}" type="text" name="name" value="{{ $rule->name }}" maxlength="100" required></td>
                        <td class="num"><input form="rule-{{ $rule->key }}" type="number" name="points" value="{{ $rule->points }}" min="0" max="1000" required style="width:80px"></td>
                        @if ($category === 'daily')
                            <td class="num"><input form="rule-{{ $rule->key }}" type="number" name="threshold" value="{{ $rule->threshold + 0 }}" min="0" step="any" required style="width:100px"><div class="hint">{{ $rule->unit }}</div></td>
                            <td class="num"><input form="rule-{{ $rule->key }}" type="number" name="max_per_day" value="{{ $rule->max_per_day }}" min="0" max="24" required style="width:70px"></td>
                        @else
                            <td class="num"><input form="rule-{{ $rule->key }}" type="number" name="cap_points" value="{{ $rule->cap_points }}" min="0" max="10000" style="width:90px"></td>
                            <td><select form="rule-{{ $rule->key }}" name="cap_period">@foreach (\App\Wellness\Models\Rule::CAP_PERIODS as $k => $l)<option value="{{ $k }}" @selected($rule->cap_period === $k)>{{ $l }}</option>@endforeach</select></td>
                        @endif
                        <td><input form="rule-{{ $rule->key }}" type="text" name="description" value="{{ $rule->description }}" maxlength="255"></td>
                        <td><input form="rule-{{ $rule->key }}" type="date" name="effective_from" value="{{ today()->addDay()->toDateString() }}" min="{{ today()->toDateString() }}" required><div class="hint">Now since {{ $rule->effective_from->format('M j, Y') }}</div></td>
                        <td><form id="rule-{{ $rule->key }}" method="post" action="{{ route('wellness.admin.rules.store', $rule->key) }}">@csrf<button class="btn btn-small" type="submit">Save</button></form></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endforeach
    <p class="hint">The daily total is always capped at {{ \App\Wellness\Scoring::dailyMax() }} points, whatever the rules add up to.</p>
</div>
@endsection
