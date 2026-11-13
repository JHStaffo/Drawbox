@extends('layouts.app', ['title' => 'Training reports'])

@section('band')
    <h1>Training reports</h1>
    <p>Who has what, how far along they are, time spent and quiz grades.</p>
@endsection

@section('content')
<div class="stack">
    <form method="get" class="card">
        <div class="field-row">
            <div class="field"><label for="f-course">Course</label><select id="f-course" name="course"><option value="">All</option>@foreach ($courses as $c)<option value="{{ $c->id }}" @selected($filters['course'] == $c->id)>{{ $c->title }}</option>@endforeach</select></div>
            <div class="field"><label for="f-user">Team Member</label><select id="f-user" name="user"><option value="">All</option>@foreach ($users as $u)<option value="{{ $u->id }}" @selected($filters['user'] == $u->id)>{{ $u->name }}</option>@endforeach</select></div>
            <div class="field"><label for="f-profile">Position</label><select id="f-profile" name="profile"><option value="">All</option>@foreach ($permissionProfiles as $p)<option value="{{ $p->id }}" @selected($filters['profile'] == $p->id)>{{ $p->label() }}</option>@endforeach</select></div>
            <div class="field"><label for="f-status">Status</label><select id="f-status" name="status">
                @foreach (['' => 'Everything current', 'open' => 'Not finished', 'not_started' => 'Not started', 'in_progress' => 'In progress', 'overdue' => 'Overdue', 'completed' => 'Completed', 'history' => 'Earlier times (reassigned)'] as $k => $label)<option value="{{ $k }}" @selected($filters['status'] === ($k ?: null))>{{ $label }}</option>@endforeach
            </select></div>
            <div class="field"><label for="f-from">Assigned from</label><input id="f-from" type="date" name="from" value="{{ $filters['from'] }}"></div>
            <div class="field"><label for="f-to">to</label><input id="f-to" type="date" name="to" value="{{ $filters['to'] }}"></div>
        </div>
        <div class="row-actions">
            <button class="btn" type="submit">Show</button>
            <a class="btn btn-outline" href="{{ route('training.reports.csv', request()->query()) }}">Download CSV</a>
            <a class="btn btn-outline" href="{{ route('training.reports.attempts', request()->query()) }}">Quiz attempts CSV</a>
        </div>
    </form>

    <div class="grid grid-4">
        <div class="card"><div class="stat-label">Assignments</div><div class="stat-value">{{ $summary['assigned'] }}</div></div>
        <div class="card"><div class="stat-label">Completed</div><div class="stat-value">{{ $summary['completed'] }}</div><div class="stat-sub">{{ $summary['assigned'] ? round($summary['completed'] * 100 / $summary['assigned']) : 0 }}%</div></div>
        <div class="card"><div class="stat-label">Overdue</div><div class="stat-value">{{ $summary['overdue'] }}</div></div>
        <div class="card"><div class="stat-label">Time spent</div><div class="stat-value">{{ \App\Training\Models\Assignment::duration($summary['seconds']) }}</div><div class="stat-sub">Average grade {{ $summary['avgScore'] !== null ? round($summary['avgScore']).'%' : '—' }}</div></div>
    </div>

    <div class="card">
        @if ($rows->isEmpty())
            <p class="empty">Nothing matches.</p>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Team Member</th><th>Course</th><th>Status</th><th style="min-width:110px">Done</th><th class="num">Time</th><th class="num">Grade</th><th class="num">Attempts</th><th>Due</th><th>Completed</th></tr></thead>
                <tbody>
                @foreach ($rows as $a)
                    <tr>
                        <td><a href="{{ route('training.person', $a->user) }}">{{ $a->user->name }}</a><div class="hint">{{ $a->user->profile?->label() }}</div></td>
                        <td><a href="{{ route('training.progress', $a->course) }}">{{ $a->course->title }}</a></td>
                        <td>@include('training.partials.status', ['assignment' => $a])</td>
                        <td>@include('training.partials.progress', ['percent' => $a->percent])<div class="hint">{{ $a->percent }}%</div></td>
                        <td class="num">{{ $a->timeLabel() }}</td>
                        <td class="num">{{ $a->score !== null ? $a->score.'%' : '—' }}</td>
                        <td class="num">{{ $a->attempts_count }}</td>
                        <td>{{ $a->due_on?->format('M j, Y') ?? '—' }}</td>
                        <td>{{ $a->completed_at?->format('M j, Y') ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</div>
@endsection
