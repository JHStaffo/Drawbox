@extends('layouts.app', ['title' => 'Wellness review'])

@section('band')
    <h1>Review queue</h1>
    <p>{{ $pending->count() }} waiting. Check that the note shows the Team Member's name, the date and a signature. Opening a note is recorded in the audit log.</p>
@endsection

@section('content')
<div class="stack">
    @forelse ($pending as $s)
        <div class="card review-item">
            <div class="review-head">
                <div>
                    <h2>{{ $s->user?->name }}: {{ $names[$s->activity] ?? $s->activity }}</h2>
                    <div class="hint">On {{ $s->dayLabel() }} · sent {{ $s->created_at->diffForHumans() }}@if ($s->original) · fixing one sent back for: {{ $s->original->reject_reason }}@endif</div>
                </div>
                @if ($s->note_path)<a class="btn btn-outline btn-small" href="{{ route('wellness.admin.review.note', $s) }}" target="_blank">Open the note</a>@endif
            </div>
            @if ($s->activity === 'inbody' && $s->measures)
                <p class="mt">Body fat {{ $s->measures['body_fat_pct'] }}% · skeletal muscle {{ $s->measures['muscle_lb'] }} lb</p>
            @endif
            @if ($s->comment)<p class="mt">“{{ $s->comment }}”</p>@endif
            @if ($s->user_id === auth()->id())
                <p class="hint mt">This is yours, so another reviewer has to decide it.</p>
            @else
                <div class="row-actions mt">
                    <form method="post" action="{{ route('wellness.admin.review.approve', $s) }}">@csrf<button class="btn btn-teal btn-small" type="submit">Approve</button></form>
                    <form method="post" action="{{ route('wellness.admin.review.reject', $s) }}" class="row-actions">@csrf
                        <input type="text" name="reason" maxlength="500" required placeholder="Why not, so they can fix it" style="min-width:260px">
                        <button class="btn btn-outline btn-small" type="submit">Send back</button>
                    </form>
                </div>
            @endif
        </div>
    @empty
        <div class="card"><p class="empty">Nothing to review.</p></div>
    @endforelse

    @if ($recent->isNotEmpty())
        <div class="card">
            <h2>Recently decided</h2>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Team Member</th><th>Activity</th><th>Date</th><th>Result</th><th class="num">Points</th><th>By</th></tr></thead>
                <tbody>
                @foreach ($recent as $s)
                    <tr>
                        <td>{{ $s->user?->name }}</td>
                        <td>{{ $names[$s->activity] ?? $s->activity }}</td>
                        <td>{{ $s->dayLabel() }}</td>
                        <td>{{ $s->statusLabel() }}@if ($s->reject_reason)<div class="hint">{{ $s->reject_reason }}</div>@endif @if ($s->points_note)<div class="hint">{{ $s->points_note }}</div>@endif</td>
                        <td class="num">{{ $s->status === 'approved' ? $s->points : '' }}</td>
                        <td>{{ $s->reviewer?->name }}<div class="hint">{{ $s->reviewed_at?->format('M j, g:i A') }}</div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
    @endif
</div>
@endsection
