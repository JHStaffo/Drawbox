@extends('layouts.app', ['title' => 'Home'])

@section('band')
    <h1>Hi, {{ explode(' ', auth()->user()->name)[0] }}</h1>
    <p>
        @if ($credits->count())
            You have {{ $credits->count() }} {{ Str::plural('draw', $credits->count()) }} waiting.
        @else
            No draws waiting right now. Bring in a call and your crew earns one each.
        @endif
    </p>
    <p>There is currently a value of <strong>{{ money($boxValue) }}</strong> in the draw box.</p>
@endsection

@section('content')
<div class="stack">
    @foreach ($lockers as $a)
        <div class="card locker-banner">
            @if ($a->isOverdue())
                <p style="margin:0 0 8px">Your time to pick up the prizes in <strong>locker {{ $a->locker->number }}</strong> at the <strong>{{ $a->locker->officeName() }}</strong> office has run out. If you already took them, press Picked Up. Otherwise a supervisor will check the locker, and prizes still in it are forfeited.</p>
            @else
                <p style="margin:0 0 8px">You have prizes waiting in <strong>locker {{ $a->locker->number }}</strong> at the <strong>{{ $a->locker->officeName() }}</strong> office. Use combination <span class="combo-big">{{ $a->combination }}</span> to open it. You have <strong>{{ $a->daysLeft() }} {{ Str::plural('day', $a->daysLeft()) }}</strong> left to retrieve your prizes before they are forfeited.</p>
            @endif
            <div class="row-actions" style="justify-content:space-between">
                <span class="hint">{{ $a->draws->map->label()->join(', ') }}</span>
                <form method="post" action="{{ route('lockers.picked-up', $a) }}">@csrf
                    <button class="btn btn-teal" type="submit">Picked Up</button>
                </form>
            </div>
        </div>
    @endforeach

    @foreach ($pending as $draw)
        <div class="card card-teal ticket">
            <div>
                <h2 style="margin:0">You won a {{ $draw->prize_name }}</h2>
                <div>{{ $draw->prize_type === 'gift_card' ? 'Pick your gift card brand to finish.' : 'Pick which one you want to finish.' }}</div>
            </div>
            <a class="btn" href="{{ route('draws.show', $draw) }}">Pick a {{ $draw->choiceNoun() }}</a>
        </div>
    @endforeach

    @foreach ($credits as $credit)
        <div class="card ticket">
            <div>
                <h2 style="margin:0">{{ $credit->call->location }}</h2>
                <div class="when">
                    Call on {{ $credit->call->occurred_at->format('M j, g:i A') }}
                    · Draw by {{ $credit->expires_at->format('M j') }}
                    @if ($credit->expires_at->diffInDays(now(), true) < 2)<span class="pill pill-red">Expires soon</span>@endif
                </div>
            </div>
            <form method="post" action="{{ route('draws.store', $credit) }}">
                @csrf
                <button class="btn btn-big" type="submit">Draw</button>
            </form>
        </div>
    @endforeach

    <div class="card">
        <h2>Recent Winners</h2>
        @if ($winners->isEmpty())
            <p class="empty">No winners yet.</p>
        @else
            <ul class="winners">
                @foreach ($winners as $win)
                    <li><span class="when">{{ $win->drawn_at->diffForHumans() }}:</span> <strong>{{ $win->user->name }}</strong> drew {{ $win->headline() }}!</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="card">
        <h2>My prizes</h2>
        <p class="hint">Prizes received in {{ now()->year }}: {{ money((int) $yearTotal) }}</p>
        @if ($history->isEmpty())
            <p class="empty">Nothing yet.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>Prize</th><th>Drawn</th><th>Status</th></tr>
                @foreach ($history as $draw)
                    <tr>
                        <td>{{ $draw->label() }}</td>
                        <td>{{ $draw->drawn_at->format('M j, Y') }}</td>
                        <td>
                            @if ($draw->status === 'awarded')
                                <span class="pill pill-green">Received {{ $draw->awarded_at->format('M j') }}</span>
                            @elseif ($draw->status === 'in_locker')
                                <span class="pill pill-teal">In locker {{ $draw->lockerAssignment?->locker->number }}</span>
                            @elseif ($draw->status === 'forfeited')
                                <span class="pill pill-red">Forfeited</span>
                            @else
                                <span class="pill">Pending Dispensing</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>
</div>
@endsection
