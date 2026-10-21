@extends('layouts.app', ['title' => 'Reports'])

@section('band')
    <h1>Reports</h1>
    <p>{{ $from->format('M j, Y') }} to {{ $to->format('M j, Y') }}</p>
@endsection

@section('content')
@php($query = ['from' => $from->toDateString(), 'to' => $to->toDateString()] + $filters)
<div class="stack">
    <form class="card" method="get">
        <div class="field-row">
            <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="{{ $from->toDateString() }}"></div>
            <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="{{ $to->toDateString() }}"></div>
            <div class="field">
                <label for="user_id">Team Member</label>
                <select id="user_id" name="user_id">
                    <option value="">Everyone</option>
                    @foreach ($employees as $e)<option value="{{ $e->id }}" @selected(($filters['user_id'] ?? '') == $e->id)>{{ $e->name }}</option>@endforeach
                </select>
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All prizes drawn</option>
                    <option value="won" @selected(($filters['status'] ?? '') === 'won')>Pending Dispensing</option>
                    <option value="in_locker" @selected(($filters['status'] ?? '') === 'in_locker')>In a locker</option>
                    <option value="forfeited" @selected(($filters['status'] ?? '') === 'forfeited')>Forfeited</option>
                    <option value="awarded" @selected(($filters['status'] ?? '') === 'awarded')>Received (by date received)</option>
                </select>
            </div>
        </div>
        <div class="row-actions">
            <button class="btn" type="submit">Run report</button>
            <a class="btn btn-outline" href="{{ route('supervisor.reports.prizes', $query) }}">Download prizes CSV</a>
            <a class="btn btn-outline" href="{{ route('supervisor.reports.won', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">Prizes won by team member</a>
        </div>
    </form>

    <div class="card">
        <div class="row-actions" style="justify-content:space-between">
            <h2 style="margin:0">Value received per team member (for taxes)</h2>
            <a class="btn btn-small btn-outline" href="{{ route('supervisor.reports.employees', $query) }}">Download CSV</a>
        </div>
        <p class="hint mt">Counts prizes by the date the team member received them. Each prize's value is what it was worth when drawn.</p>
        @if ($totals->isEmpty())
            <p class="empty">No prizes received in this range.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>Team Member</th><th class="num">Prizes</th><th class="num">Taxable</th><th class="num">Of which cash bonus</th><th class="num">Non-taxable</th><th class="num">PTO hrs</th></tr>
                @foreach ($totals as $t)
                    <tr>
                        <td>{{ $t['user']->name }}</td>
                        <td class="num">{{ $t['count'] }}</td>
                        <td class="num">{{ money($t['taxable_cents']) }}</td>
                        <td class="num">{{ money($t['cash_bonus_cents']) }}</td>
                        <td class="num">{{ money($t['nontaxable_cents']) }}</td>
                        <td class="num">{{ $t['pto_hours'] + 0 }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td><strong>Total</strong></td>
                    <td class="num"><strong>{{ $totals->sum('count') }}</strong></td>
                    <td class="num"><strong>{{ money($totals->sum('taxable_cents')) }}</strong></td>
                    <td class="num"><strong>{{ money($totals->sum('cash_bonus_cents')) }}</strong></td>
                    <td class="num"><strong>{{ money($totals->sum('nontaxable_cents')) }}</strong></td>
                    <td class="num"><strong>{{ $totals->sum('pto_hours') + 0 }}</strong></td>
                </tr>
            </table></div>
        @endif
    </div>

    <div class="card">
        <h2>Prizes ({{ $draws->count() }})</h2>
        @if ($draws->isEmpty())
            <p class="empty">No prizes match.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>Drawn</th><th>Team Member</th><th>Prize</th><th class="num">Value</th><th>Status</th><th>Handed out by</th>@if (auth()->user()->hasRole('admin'))<th></th>@endif</tr>
                @foreach ($draws as $d)
                    <tr>
                        <td>{{ $d->drawn_at->format('M j, Y') }}</td>
                        <td>{{ $d->user->name }}</td>
                        <td>{{ $d->label() }}</td>
                        <td class="num">{{ money($d->value_cents) }}</td>
                        <td>
                            @if ($d->status === 'awarded')<span class="pill pill-green">{{ $d->cash_bonus ? 'Paid' : 'Received' }} {{ $d->awarded_at->format('M j') }}</span>
                            @elseif ($d->status === 'pending_brand')<span class="pill pill-teal">Picking brand</span>
                            @elseif ($d->status === 'in_locker')<span class="pill pill-teal">In locker</span>
                            @elseif ($d->status === 'forfeited')<span class="pill pill-red">Forfeited</span>
                            @else<span class="pill">Pending Dispensing</span>@endif
                        </td>
                        <td>{{ $d->awarder?->name }}</td>
                        @if (auth()->user()->hasRole('admin'))
                            <td class="right"><div class="row-actions" style="justify-content:flex-end;flex-wrap:nowrap">@include('admin.partials.draw-actions', ['draw' => $d])</div></td>
                        @endif
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>
</div>
@endsection
