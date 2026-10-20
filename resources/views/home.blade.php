@extends('layouts.app', ['title' => 'My draws'])

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
    @foreach ($pending as $draw)
        <div class="card card-teal ticket">
            <div>
                <h2 style="margin:0">You won a {{ $draw->prize_name }}</h2>
                <div>Pick your gift card brand to finish.</div>
            </div>
            <a class="btn" href="{{ route('draws.show', $draw) }}">Pick a brand</a>
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
                            @else
                                <span class="pill">Pick up from a supervisor</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>
</div>
@endsection
