@extends('layouts.app', ['title' => 'Dashboard'])

@section('band')
    <h1>Supervisor dashboard</h1>
    <p>There is currently a value of <strong>{{ money($boxValue) }}</strong> in the draw box.</p>
@endsection

@section('content')
<div class="stack">
    <h2 style="margin-bottom:0">Prizes received</h2>
    <div class="grid grid-4">
        @foreach ($periods as $label => $p)
            <div class="card">
                <div class="stat-label">{{ $label }}</div>
                <div class="stat-value">{{ money($p['value_cents']) }}</div>
                <div class="stat-sub">{{ $p['count'] }} {{ Str::plural('prize', $p['count']) }}@if ($p['pto_hours']) · {{ rtrim(rtrim(number_format($p['pto_hours'], 2), '0'), '.') }} PTO hrs @endif</div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <h2>Won, waiting to be handed out ({{ $unawarded->count() }})</h2>
        @if ($unawarded->isEmpty())
            <p class="empty">Everything won has been handed out.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>Employee</th><th>Prize</th><th class="num">Value</th><th>Drawn</th><th></th></tr>
                @foreach ($unawarded as $draw)
                    <tr>
                        <td>{{ $draw->user->name }}</td>
                        <td>{{ $draw->label() }}</td>
                        <td class="num">{{ money($draw->value_cents) }}</td>
                        <td>{{ $draw->drawn_at->format('M j') }}</td>
                        <td class="right">
                            @if ($draw->status === 'pending_brand')
                                <span class="pill pill-teal">Picking brand</span>
                            @else
                                <form method="post" action="{{ route('supervisor.award', $draw) }}">@csrf
                                    <button class="btn btn-small btn-teal" type="submit">Mark received</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h2>Not drawn yet ({{ $undrawn->count() }})</h2>
            @if ($undrawn->isEmpty())
                <p class="empty">No open draws.</p>
            @else
                <div class="table-wrap"><table>
                    <tr><th>Employee</th><th>Call</th><th>Expires</th><th></th></tr>
                    @foreach ($undrawn as $credit)
                        <tr>
                            <td>{{ $credit->user->name }}</td>
                            <td>{{ $credit->call->location }}<div class="hint">{{ $credit->call->occurred_at->format('M j') }}</div></td>
                            <td>{{ $credit->expires_at->format('M j') }}</td>
                            <td class="right">
                                <form method="post" action="{{ route('supervisor.credits.void', $credit) }}" onsubmit="return confirm('Remove this draw for {{ $credit->user->name }}?')">@csrf
                                    <button class="btn btn-small btn-outline" type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </table></div>
            @endif
        </div>

        <div class="card">
            <h2>Current odds</h2>
            <div class="table-wrap"><table>
                <tr><th>Prize</th><th class="num">Left</th><th class="num">Odds</th><th></th></tr>
                @foreach ($odds as $o)
                    <tr>
                        <td>{{ $o['prize']->name }}</td>
                        <td class="num">{{ $o['count'] }}</td>
                        <td class="num">{{ number_format($o['odds'] * 100, 1) }}%</td>
                        <td><div class="odds-bar"><span style="width: {{ round($o['odds'] * 100, 1) }}%"></span></div></td>
                    </tr>
                @endforeach
            </table></div>
        </div>
    </div>
</div>
@endsection
