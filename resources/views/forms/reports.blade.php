@extends('layouts.app', ['title' => 'Form reports'])

@section('band')
    <h1>Form reports</h1>
    <p>{{ $form?->name ?? 'All forms' }} · {{ $f['start']->format('M j, Y') }} to {{ $f['end']->format('M j, Y') }} · {{ $submissions->count() }} {{ Str::plural('submission', $submissions->count()) }}</p>
@endsection

@push('scripts')
<script>
// Picking dates switches the period to Custom dates; picking a period clears the dates.
(function () {
    const period = document.getElementById('period');
    ['from', 'to'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', function () { period.value = 'custom'; });
    });
    period.addEventListener('change', function () {
        if (period.value !== 'custom') { document.getElementById('from').value = ''; document.getElementById('to').value = ''; }
    });
})();
</script>
@endpush

@section('content')
@php($query = array_filter(['period' => $f['period'], 'from' => $f['period'] === 'custom' ? $f['start']->toDateString() : null, 'to' => $f['period'] === 'custom' ? $f['end']->toDateString() : null, 'form' => $f['form'] ?? null, 'member' => $f['member'] ?? null, 'position' => $f['position'] ?? null, 'sender' => $f['sender'] ?? null, 'group' => $group]))
<div class="stack">
    <form class="card" method="get" action="{{ route('forms.reports') }}">
        <div class="field-row">
            <div class="field">
                <label for="period">Period</label>
                <select id="period" name="period">@foreach ($periods as $k => $l)<option value="{{ $k }}" @selected($f['period'] === $k)>{{ $l }}</option>@endforeach</select>
            </div>
            <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="{{ $f['period'] === 'custom' ? $f['start']->toDateString() : '' }}"></div>
            <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="{{ $f['period'] === 'custom' ? $f['end']->toDateString() : '' }}"></div>
            <div class="field">
                <label for="form">Form</label>
                <select id="form" name="form"><option value="">All forms</option>@foreach ($forms as $fm)<option value="{{ $fm->id }}" @selected(($f['form'] ?? null) == $fm->id)>{{ $fm->name }}</option>@endforeach</select>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label for="member">Team Member</label>
                <select id="member" name="member"><option value="">Everyone</option>@foreach ($people as $p)<option value="{{ $p->id }}" @selected(($f['member'] ?? null) == $p->id)>{{ $p->name }}</option>@endforeach</select>
            </div>
            <div class="field">
                <label for="position">Position</label>
                <select id="position" name="position"><option value="">Any</option>@foreach ($profiles as $pr)<option value="{{ $pr->id }}" @selected(($f['position'] ?? null) == $pr->id)>{{ $pr->label() }}</option>@endforeach</select>
            </div>
            <div class="field">
                <label for="sender">Sent by</label>
                <select id="sender" name="sender"><option value="">Anyone</option>@foreach ($people as $p)<option value="{{ $p->id }}" @selected(($f['sender'] ?? null) == $p->id)>{{ $p->name }}</option>@endforeach</select>
            </div>
            <div class="field">
                <label for="group">Totals by</label>
                <select id="group" name="group">@foreach ($groups as $k => $l)<option value="{{ $k }}" @selected($group === $k)>{{ $l }}</option>@endforeach</select>
            </div>
        </div>
        <p class="hint">Team Member is who the form is about (its Team Member answer, or whoever sent it if the form doesn't ask). Position is that person's permission profile; a main profile includes its sub-profiles. Pick a form to total by its choice questions too. Weeks run Monday to Sunday.</p>
        <div class="row-actions">
            <button class="btn" type="submit">Run report</button>
            <a class="btn btn-outline" href="{{ route('forms.reports.csv', $query) }}">Download CSV</a>
        </div>
    </form>

    <div class="card" style="max-width:640px">
        <h2>Totals by {{ $groups[$group] }}</h2>
        @if ($totals->isEmpty())
            <p class="empty">No submissions match.</p>
        @else
            @php($max = $totals->max('count'))
            <div class="table-wrap"><table>
                <tr><th>{{ $groups[$group] }}</th><th class="num">Count</th><th></th></tr>
                @foreach ($totals as $row)
                    <tr><td>{{ $row['label'] }}</td><td class="num">{{ $row['count'] }}</td><td style="width:40%"><div class="odds-bar"><span style="width:{{ round($row['count'] / $max * 100) }}%"></span></div></td></tr>
                @endforeach
                <tr><td><strong>Total submissions</strong></td><td class="num"><strong>{{ $submissions->count() }}</strong></td><td></td></tr>
            </table></div>
            @if (str_starts_with($group, 'q:') && $form?->fields->firstWhere('label', substr($group, 2))?->type === 'checkboxes')<p class="hint">Checkbox answers count once for each box ticked.</p>@endif
        @endif
    </div>

    <div class="card">
        <h2>Submissions</h2>
        @if ($submissions->isEmpty())
            <p class="empty">No submissions match.</p>
        @else
            <div class="table-wrap"><table>
                <tr>
                    <th>Sent</th>@unless ($form)<th>Form</th>@endunless<th>Team Member</th><th>Position</th><th>Sent by</th>
                    @foreach ($labels as $l)@if ($l !== $form?->fields->firstWhere('type', 'team_member')?->label)<th>{{ $l }}</th>@endif @endforeach
                    @unless ($form)<th>Answers</th>@endunless
                </tr>
                @foreach ($submissions->take(500) as $s)
                    <tr>
                        <td style="white-space:nowrap">{{ $s->created_at->format('M j, Y g:i A') }}</td>
                        @unless ($form)<td>{{ $s->form_name }}</td>@endunless
                        <td>{{ $s->subject?->name ?? '—' }}</td>
                        <td>{{ $s->subject?->profile?->label() ?? '—' }}</td>
                        <td>{{ $s->user?->name ?? '—' }}</td>
                        @foreach ($labels as $l)@if ($l !== $form?->fields->firstWhere('type', 'team_member')?->label)<td style="white-space:pre-line">{{ $s->answer($l) ?? '' }}</td>@endif @endforeach
                        @unless ($form)
                            <td><details><summary>Show</summary><dl class="answers mt">@foreach ($s->answers as $a)<dt>{{ $a['label'] }}</dt><dd>{{ $a['value'] ?? '—' }}</dd>@endforeach</dl></details></td>
                        @endunless
                    </tr>
                @endforeach
            </table></div>
            @if ($submissions->count() > 500)<p class="hint mt">Showing the newest 500. The CSV has all {{ $submissions->count() }}.</p>@endif
        @endif
    </div>
</div>
@endsection
