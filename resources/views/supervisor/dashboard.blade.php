@extends('layouts.app', ['title' => 'Dashboard'])

@section('band')
    <h1>Supervisor dashboard</h1>
    <p>There is currently a value of <strong>{{ money($boxValue) }}</strong> in the draw box.</p>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.check-all').forEach(function (all) {
    all.addEventListener('change', function () {
        document.querySelectorAll('input[form="' + all.dataset.form + '"][name="draws[]"]').forEach(function (box) { box.checked = all.checked; });
    });
});
</script>
@endpush

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
        <h2>Won, pending dispensing ({{ $unawarded->count() }})</h2>
        @if ($unawarded->isEmpty())
            <p class="empty">Everything won has been handed out.</p>
        @else
            <p class="hint">Check the prizes you're handing one employee, then press Dispense checked to give them directly or put them in a locker. PTO and cash bonuses don't go in lockers, so they have their own button.</p>
            <div class="table-wrap"><table class="dispense-table">
                <tr><th style="width:36px"></th><th>Prize</th><th class="num">Value</th><th>Drawn</th><th></th></tr>
                @foreach ($unawarded->groupBy('user_id') as $userDraws)
                    @php($employee = $userDraws->first()->user)
                    @php($dispensable = $userDraws->filter(fn ($d) => $d->status === 'won' && ! $d->cash_bonus && $d->pto_hours === null))
                    <tr class="group-row">
                        <td>@if ($dispensable->isNotEmpty())<input type="checkbox" class="check-all" data-form="d{{ $employee->id }}" title="Check all of {{ $employee->name }}'s prizes" aria-label="Check all of {{ $employee->name }}'s prizes">@endif</td>
                        <td colspan="3"><strong>{{ $employee->name }}</strong></td>
                        <td class="right">
                            @if ($dispensable->isNotEmpty())
                                <form id="d{{ $employee->id }}" method="get" action="{{ route('supervisor.dispense', $employee) }}">
                                    <button class="btn btn-small btn-teal" type="submit">Dispense checked</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @foreach ($userDraws as $draw)
                        <tr>
                            <td>@if ($dispensable->contains($draw))<input type="checkbox" form="d{{ $employee->id }}" name="draws[]" value="{{ $draw->id }}" aria-label="Dispense {{ $draw->label() }}">@endif</td>
                            <td>{{ $draw->label() }}</td>
                            <td class="num">{{ money($draw->value_cents) }}</td>
                            <td>{{ $draw->drawn_at->format('M j') }}</td>
                            <td class="right"><div class="row-actions" style="justify-content:flex-end">
                                @if ($draw->status === 'pending_brand')
                                    <span class="pill pill-teal">Picking brand</span>
                                @elseif ($draw->cash_bonus || $draw->pto_hours !== null)
                                    <form method="post" action="{{ route('supervisor.award', $draw) }}">@csrf
                                        <button class="btn btn-small btn-teal" type="submit">{{ $draw->cash_bonus ? 'Mark paid' : 'Mark PTO given' }}</button>
                                    </form>
                                @endif
                                @include('admin.partials.draw-actions')
                            </div></td>
                        </tr>
                    @endforeach
                @endforeach
            </table></div>
        @endif
    </div>

    @if ($lockerAssignments->isNotEmpty())
        <div class="card">
            <div class="row-actions" style="justify-content:space-between">
                <h2 style="margin:0">In lockers ({{ $lockerAssignments->count() }})</h2>
                @if (auth()->user()->hasRole('admin'))<a class="btn btn-small btn-outline" href="{{ route('admin.lockers') }}">Locker map</a>@endif
            </div>
            <div class="table-wrap mt"><table>
                <tr><th>Locker</th><th>Employee</th><th>Prizes</th><th>Pick up by</th><th></th></tr>
                @foreach ($lockerAssignments as $a)
                    <tr>
                        <td><strong>{{ $a->locker->number }}</strong> · {{ $a->locker->officeName() }}</td>
                        <td>{{ $a->user->name }}</td>
                        <td>{{ $a->draws->map->label()->join(', ') }}</td>
                        <td>
                            @if ($a->isForfeited())<span class="pill pill-red">Forfeited</span>
                            @else{{ $a->due_at->format('M j') }} <span class="hint">({{ $a->daysLeft() }} {{ Str::plural('day', $a->daysLeft()) }} left)</span>@endif
                        </td>
                        <td class="right"><div class="row-actions" style="justify-content:flex-end">
                            @if ($a->isForfeited())
                                <form method="post" action="{{ route('supervisor.lockers.clear', $a) }}" onsubmit="return confirm(@js('Did you take the forfeited prizes out of locker '.$a->locker->number.'? It will show as open again.'))">@csrf
                                    <button class="btn btn-small btn-teal" type="submit">Emptied</button>
                                </form>
                            @else
                                <form method="post" action="{{ route('supervisor.lockers.picked-up', $a) }}" onsubmit="return confirm(@js('Mark locker '.$a->locker->number.' as picked up by '.$a->user->name.'?'))">@csrf
                                    <button class="btn btn-small btn-outline" type="submit">Picked up</button>
                                </form>
                            @endif
                        </div></td>
                    </tr>
                @endforeach
            </table></div>
        </div>
    @endif

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
