@extends('layouts.app', ['title' => 'PTO earned by team member'])

@section('band')
    <h1>PTO earned by team member</h1>
    <p>PTO prizes drawn from {{ $from->format('M j, Y') }} to {{ $to->format('M j, Y') }}. Forfeited PTO isn't counted.</p>
@endsection

@section('content')
@php($query = array_filter(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'user_id' => $userId]))
<div class="stack">
    <form class="card" method="get">
        <div class="field-row">
            <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="{{ $from->toDateString() }}"></div>
            <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="{{ $to->toDateString() }}"></div>
            <div class="field">
                <label for="user_id">Team Member</label>
                <select id="user_id" name="user_id">
                    <option value="">Everyone</option>
                    @foreach ($employees as $e)
                        <option value="{{ $e->id }}" @selected($userId === $e->id)>{{ $e->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="row-actions">
            <button class="btn" type="submit">Run report</button>
            <a class="btn btn-outline" href="{{ route('supervisor.reports.pto.csv', $query) }}">Download CSV</a>
            <a class="btn btn-outline" href="{{ route('supervisor.reports', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">Back to reports</a>
        </div>
    </form>

    @if ($rows->isEmpty())
        <div class="card"><p class="empty">No PTO was earned in this range.</p></div>
    @else
        <div class="card">
            <h2>Summary</h2>
            <div class="table-wrap"><table>
                <tr><th>Team Member</th><th class="num">PTO prizes</th><th class="num">Hours earned</th><th class="num">Hours received</th><th class="num">Hours pending</th></tr>
                @foreach ($rows as $r)
                    <tr>
                        <td>{{ $r['user']->name }}</td>
                        <td class="num">{{ $r['draws']->count() }}</td>
                        <td class="num">{{ $r['hours'] + 0 }}</td>
                        <td class="num">{{ $r['received_hours'] + 0 }}</td>
                        <td class="num">{{ $r['hours'] - $r['received_hours'] + 0 }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td><strong>Total</strong></td>
                    <td class="num"><strong>{{ $rows->sum(fn ($r) => $r['draws']->count()) }}</strong></td>
                    <td class="num"><strong>{{ $rows->sum('hours') + 0 }}</strong></td>
                    <td class="num"><strong>{{ $rows->sum('received_hours') + 0 }}</strong></td>
                    <td class="num"><strong>{{ $rows->sum('hours') - $rows->sum('received_hours') + 0 }}</strong></td>
                </tr>
            </table></div>
            <p class="hint mt">Received means a supervisor marked the PTO as given. Pending is PTO that is still Pending Dispensing.</p>
        </div>

        @foreach ($rows as $r)
            <div class="card">
                <h2>{{ $r['user']->name }}</h2>
                <div class="table-wrap"><table>
                    <tr><th>Drawn</th><th>Prize</th><th class="num">PTO hours</th><th>Status</th></tr>
                    @foreach ($r['draws'] as $d)
                        <tr>
                            <td>{{ $d->drawn_at->format('M j, Y') }}</td>
                            <td>{{ $d->prize_name }}</td>
                            <td class="num">{{ $d->pto_hours + 0 }}</td>
                            <td>
                                @if ($d->status === 'awarded')<span class="pill pill-green">Received {{ $d->awarded_at?->format('M j') }}</span>
                                @else<span class="pill">Pending Dispensing</span>@endif
                            </td>
                        </tr>
                    @endforeach
                    <tr>
                        <td colspan="2"><strong>Total</strong></td>
                        <td class="num"><strong>{{ $r['hours'] + 0 }}</strong></td>
                        <td></td>
                    </tr>
                </table></div>
            </div>
        @endforeach
    @endif
</div>
@endsection
