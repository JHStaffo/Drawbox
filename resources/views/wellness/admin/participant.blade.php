@extends('layouts.app', ['title' => $user->name.' · Wellness'])

@section('band')
    <h1>{{ $user->name }}</h1>
    <p>Wellness since {{ $participant->joined_at->format('F j, Y') }} · {{ $participant->isActive() ? 'In the program' : 'Left' }} · {{ number_format($points->sum('points')) }} points in the ledger</p>
@endsection

@section('content')
<div class="stack">
    <p><a href="{{ route('wellness.admin.participants') }}">‹ All participants</a></p>
    <div class="grid grid-2">
        <form class="card" method="post" action="{{ route('wellness.admin.adjust', $participant) }}">@csrf
            <h2>Adjust points</h2>
            <p class="hint">Adds a new line to the ledger with your reason; nothing already recorded changes. Daily-habit points still can't go over {{ \App\Wellness\Scoring::dailyMax() }} for a day. Use a negative number to take points away.</p>
            <div class="field-row">
                <div class="field"><label for="day">Day</label><input id="day" type="date" name="day" required max="{{ today()->toDateString() }}" value="{{ old('day') }}"></div>
                <div class="field"><label for="category">Kind</label><select id="category" name="category"><option value="daily">Daily habits</option><option value="other" @selected(old('category') === 'other')>Other activities</option></select></div>
                <div class="field"><label for="points">Points</label><input id="points" type="number" name="points" required min="-1000" max="1000" value="{{ old('points') }}"></div>
            </div>
            <div class="field"><label for="reason">Reason</label><input id="reason" type="text" name="reason" required maxlength="255" value="{{ old('reason') }}" placeholder="Watch didn't sync during the Dec 3 outage"></div>
            <button class="btn" type="submit">Add adjustment</button>
        </form>
        <div class="card">
            <h2>Rewards</h2>
            @forelse ($awards as $a)
                <p>{{ $a->label() }} · {{ $a->statusLabel() }}@if ($a->brand) · {{ $a->brand }}@endif</p>
            @empty
                <p class="empty">None yet.</p>
            @endforelse
            <h2 class="mt">Submissions</h2>
            @forelse ($submissions as $s)
                <p>{{ $names[$s->activity] ?? $s->activity }}, {{ $s->dayLabel() }} · {{ $s->statusLabel() }}@if ($s->status === 'approved') · {{ $s->points }} pts @endif @if ($s->reviewer)<span class="hint">by {{ $s->reviewer->name }}</span>@endif</p>
            @empty
                <p class="empty">None yet.</p>
            @endforelse
        </div>
    </div>

    <div class="card">
        <h2>Points ledger</h2>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Day</th><th>What</th><th>Kind</th><th class="num">Points</th><th>Reason</th><th>Recorded</th></tr></thead>
            <tbody>
            @forelse ($points as $p)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($p->day)->format('D M j, Y') }}</td>
                    <td>{{ $p->activity === 'adjustment' ? 'Adjustment' : ($names[$p->activity] ?? $p->activity) }}</td>
                    <td>{{ $p->category === 'daily' ? 'Daily habit' : 'Other' }}</td>
                    <td class="num">{{ $p->points > 0 && $p->kind !== 'earned' ? '+' : '' }}{{ $p->points }}</td>
                    <td>{{ $p->reason }}@if ($p->created_by)<div class="hint">by {{ $adjusters[$p->created_by] ?? 'an admin' }}</div>@endif</td>
                    <td class="hint">{{ $p->created_at->format('M j, g:i A') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No points yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        <p class="hint mt">Daily-habit points are worked out again from the Team Member's data while a week is open, so their rows are refreshed until it closes. Adjustments are never changed.</p>
    </div>
</div>
@endsection
