@extends('layouts.app', ['title' => 'Prizes won by employee'])

@section('band')
    <h1>Prizes won by employee</h1>
    <p>Every prize drawn from {{ $from->format('M j, Y') }} to {{ $to->format('M j, Y') }}, whether or not it has been handed out yet.</p>
@endsection

@section('content')
@php($query = ['from' => $from->toDateString(), 'to' => $to->toDateString()])
<div class="stack">
    <form class="card" method="get">
        <div class="field-row">
            <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="{{ $from->toDateString() }}"></div>
            <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="{{ $to->toDateString() }}"></div>
        </div>
        <div class="row-actions">
            <button class="btn" type="submit">Run report</button>
            <a class="btn btn-outline" href="{{ route('supervisor.reports.won.csv', $query) }}">Download CSV</a>
            <a class="btn btn-outline" href="{{ route('supervisor.reports', $query) }}">Back to reports</a>
        </div>
    </form>

    @if ($rows->isEmpty())
        <div class="card"><p class="empty">No prizes were won in this range.</p></div>
    @else
        <div class="card">
            <h2>Summary</h2>
            <div class="table-wrap"><table>
                <tr><th>Employee</th><th class="num">Prizes</th><th class="num">Prize value</th><th class="num">PTO hours</th></tr>
                @foreach ($rows as $r)
                    <tr><td>{{ $r['user']->name }}</td><td class="num">{{ $r['count'] }}</td><td class="num">{{ money($r['value_cents']) }}</td><td class="num">{{ $r['pto_hours'] + 0 }}</td></tr>
                @endforeach
                <tr>
                    <td><strong>Total</strong></td>
                    <td class="num"><strong>{{ $rows->sum('count') }}</strong></td>
                    <td class="num"><strong>{{ money($rows->sum('value_cents')) }}</strong></td>
                    <td class="num"><strong>{{ $rows->sum('pto_hours') + 0 }}</strong></td>
                </tr>
            </table></div>
            <p class="hint mt">Prize value doesn't include PTO prizes, which are totaled in hours.</p>
        </div>

        @foreach ($rows as $r)
            <div class="card">
                <h2>{{ $r['user']->name }}</h2>
                <div class="table-wrap"><table>
                    <tr><th>Drawn</th><th>Prize</th><th class="num">Value</th><th class="num">PTO hours</th><th>Status</th>@if (auth()->user()->hasRole('admin'))<th></th>@endif</tr>
                    @foreach ($r['draws'] as $d)
                        <tr>
                            <td>{{ $d->drawn_at->format('M j, Y') }}</td>
                            <td>{{ $d->label() }}</td>
                            <td class="num">{{ $d->pto_hours === null ? money($d->value_cents) : '' }}</td>
                            <td class="num">{{ $d->pto_hours === null ? '' : $d->pto_hours + 0 }}</td>
                            <td>
                                @if ($d->status === 'awarded')<span class="pill pill-green">{{ $d->cash_bonus ? 'Paid' : 'Received' }}</span>
                                @elseif ($d->status === 'pending_brand')<span class="pill pill-teal">Picking brand</span>
                            @elseif ($d->status === 'in_locker')<span class="pill pill-teal">In locker</span>
                            @elseif ($d->status === 'forfeited')<span class="pill pill-red">Forfeited</span>
                                @else<span class="pill">Pending Dispensing</span>@endif
                            </td>
                            @if (auth()->user()->hasRole('admin'))
                                <td class="right"><div class="row-actions" style="justify-content:flex-end;flex-wrap:nowrap">@include('admin.partials.draw-actions', ['draw' => $d])</div></td>
                            @endif
                        </tr>
                    @endforeach
                    <tr>
                        <td colspan="2"><strong>Total ({{ $r['count'] }} {{ Str::plural('prize', $r['count']) }})</strong></td>
                        <td class="num"><strong>{{ money($r['value_cents']) }}</strong></td>
                        <td class="num"><strong>{{ $r['pto_hours'] + 0 }}</strong></td>
                        <td></td>
                        @if (auth()->user()->hasRole('admin'))<td></td>@endif
                    </tr>
                </table></div>
            </div>
        @endforeach
    @endif
</div>
@endsection
