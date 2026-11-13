@extends('layouts.app', ['title' => 'Wellness rewards'])

@section('band')
    <h1>Rewards</h1>
    <p>Weekly gift cards are created each morning for weeks that have closed ({{ \App\Wellness\Calendar::graceDays() }} days after Saturday), and the Team Member is emailed to pick a card.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card row-actions">
        @foreach (['pending' => 'To send', 'sent' => 'Sent', 'void' => 'Voided', 'all' => 'All'] as $key => $label)
            <a href="{{ route('wellness.admin.rewards', ['status' => $key]) }}" @class(['btn btn-small', 'btn-outline' => $status !== $key, 'btn-teal' => $status === $key])>{{ $label }}@if ($key !== 'all') ({{ $counts[$key] ?? 0 }})@endif</a>
        @endforeach
        <span style="flex:1"></span>
        <a class="btn btn-outline btn-small" href="{{ route('wellness.admin.rewards.csv') }}">All rewards CSV</a>
        <form method="get" action="{{ route('wellness.admin.reports.week') }}" class="row-actions">
            <input type="date" name="week" value="{{ $lastWeek->toDateString() }}" style="max-width:170px" aria-label="A day in the week">
            <button class="btn btn-outline btn-small" type="submit">Weekly points CSV</button>
        </form>
    </div>
    <div class="card">
        @if ($awards->isEmpty())
            <p class="empty">Nothing here.</p>
        @else
            <div class="table-wrap">
            <table>
                <thead><tr><th>Team Member</th><th>Reward</th><th>Gift card</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach ($awards as $a)
                    <tr>
                        <td>{{ $a->user?->name }}</td>
                        <td>{{ $a->label() }}@if ($a->test_period) <span class="pill">Test period</span>@endif<div class="hint">{{ $a->points }} daily points</div></td>
                        <td>{{ $a->brand ?? 'Not picked yet' }}</td>
                        <td>{{ $a->statusLabel() }}@if ($a->sent_at)<div class="hint">{{ $a->sent_at->format('M j') }} by {{ $a->sender?->name }}</div>@endif @if ($a->status_note)<div class="hint">{{ $a->status_note }}</div>@endif</td>
                        <td class="right">
                            @if ($a->status === 'pending')
                                <div class="row-actions" style="justify-content:flex-end">
                                    <form method="post" action="{{ route('wellness.admin.rewards.sent', $a) }}">@csrf<button class="btn btn-teal btn-small" type="submit">Mark sent</button></form>
                                    <details><summary>Void</summary>
                                        <form method="post" action="{{ route('wellness.admin.rewards.void', $a) }}" class="row-actions mt">@csrf
                                            <input type="text" name="reason" required maxlength="255" placeholder="Reason">
                                            <button class="btn btn-outline btn-small" type="submit">Void</button>
                                        </form>
                                    </details>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>
@endsection
