@extends('layouts.app', ['title' => 'Wellness participants'])

@section('band')
    <h1>Wellness participants</h1>
    <p>{{ $participants->where('status', 'active')->count() }} in the program. Week of {{ \App\Wellness\Calendar::weekStart()->format('M j') }}.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        @if ($participants->isEmpty())
            <p class="empty">Nobody has joined yet. Team Members join from Wellness › My Wellness once their permission profile includes Wellness.</p>
        @else
            <div class="table-wrap">
            <table>
                <thead><tr><th>Team Member</th><th>Status</th><th>Joined</th><th class="num">This week</th><th>Last device sync</th><th>Device exception</th></tr></thead>
                <tbody>
                @foreach ($participants as $p)
                    @php($syncs = ($last[$p->user_id] ?? collect())->whereNotIn('source', ['manual', 'admin']))
                    <tr>
                        <td>{{ $p->user->name }}</td>
                        <td>@if ($p->isActive())<span class="pill pill-green">In</span>@else<span class="pill">Left {{ $p->withdrawn_at?->format('M j') }}</span>@endif</td>
                        <td>{{ $p->joined_at->format('M j, Y') }}<div class="hint">Rules {{ $p->consent_version }}</div></td>
                        <td class="num">{{ (int) ($week[$p->user_id] ?? 0) }} / {{ $target }}</td>
                        <td>
                            @forelse ($syncs as $s)
                                <div>{{ \App\Wellness\Models\Reading::SOURCES[$s->source] ?? $s->source }}: {{ \Carbon\Carbon::parse($s->last)->diffForHumans() }}</div>
                            @empty
                                <span class="hint">None</span>
                            @endforelse
                        </td>
                        <td>
                            @if (auth()->user()->hasPermission('wellness_review'))
                                <details>
                                    <summary>{{ $p->manual_device_until ? 'Until '.$p->manual_device_until->format('M j') : 'None' }}</summary>
                                    <form method="post" action="{{ route('wellness.admin.exception', $p) }}" class="mt">@csrf @method('put')
                                        <div class="field">
                                            <label>Can type in steps, sleep and workout until</label>
                                            <input type="date" name="manual_device_until" value="{{ $p->manual_device_until?->toDateString() }}" min="{{ today()->toDateString() }}">
                                        </div>
                                        <div class="field">
                                            <label>Reason</label>
                                            <input type="text" name="exception_note" value="{{ $p->exception_note }}" maxlength="255" placeholder="Watch in for repair">
                                        </div>
                                        <button class="btn btn-small" type="submit">Save</button>
                                        <div class="hint">Leave the date blank to remove the exception.</div>
                                    </form>
                                </details>
                            @else
                                {{ $p->manual_device_until ? 'Until '.$p->manual_device_until->format('M j') : 'None' }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
    @if ($notJoined->isNotEmpty())
        <details class="card">
            <summary>Can join but haven't ({{ $notJoined->count() }})</summary>
            <ul class="plain-list mt">@foreach ($notJoined as $u)<li>{{ $u->name }}</li>@endforeach</ul>
        </details>
    @endif
</div>
@endsection
