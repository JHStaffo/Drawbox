@extends('layouts.app', ['title' => $course->title.' progress'])

@section('band')
    <h1>{{ $course->title }}</h1>
    <p>{{ $current->whereNotNull('completed_at')->count() }} of {{ $current->count() }} completed.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <div class="row-actions" style="justify-content:space-between">
            <h2 style="margin:0">Assigned</h2>
            @if ($canAssign && $course->isPublished())<a class="btn btn-small" href="{{ route('training.assign', ['course' => $course->id]) }}">Assign to more people</a>@endif
        </div>
        @if ($current->isEmpty())
            <p class="empty">Nobody has this course yet.</p>
        @else
        <form method="post" action="{{ route('training.assignments.reassign') }}" id="reassign">@csrf
            <div class="table-wrap mt"><table>
                <thead><tr>@if ($canAssign)<th style="width:32px"><input type="checkbox" id="all" aria-label="Select all"></th>@endif<th>Team Member</th><th>Status</th><th style="min-width:120px">Done</th><th class="num">Time</th><th class="num">Grade</th><th>Quiz attempts</th><th>Due</th><th>Assigned</th></tr></thead>
                <tbody>
                @foreach ($current as $a)
                    <tr>
                        @if ($canAssign)<td><input type="checkbox" class="pick" name="assignment_ids[]" value="{{ $a->id }}" aria-label="Select {{ $a->user->name }}"></td>@endif
                        <td><a href="{{ route('training.person', $a->user) }}">{{ $a->user->name }}</a></td>
                        <td>@include('training.partials.status', ['assignment' => $a])</td>
                        <td>@include('training.partials.progress', ['percent' => $a->percent])<div class="hint">{{ $a->percent }}%@if ($a->completed_at) · {{ $a->completed_at->format('M j, Y') }}@endif</div></td>
                        <td class="num">{{ $a->timeLabel() }}</td>
                        <td class="num">{{ $a->score !== null ? $a->score.'%' : '—' }}</td>
                        <td>
                            @foreach ($quizzes as $quiz)
                                @php($used = optional(($attempts[$a->id] ?? collect())->firstWhere('lesson_id', $quiz->id))->n ?? 0)
                                @php($qp = ($quizProgress[$a->id] ?? collect())->firstWhere('lesson_id', $quiz->id))
                                <div class="hint">{{ $quizzes->count() > 1 ? $quiz->title.': ' : '' }}{{ $used }}{{ $quiz->max_attempts ? ' of '.($quiz->max_attempts + ($qp?->extra_attempts ?? 0)) : '' }}@if ($qp?->completed_at) ✓@endif</div>
                            @endforeach
                        </td>
                        <td>{{ $a->due_on?->format('M j, Y') ?? '—' }}</td>
                        <td>{{ $a->created_at->format('M j, Y') }}<div class="hint">{{ $a->trainingProfile?->name ?? $a->assigner?->name ?? 'Automatic' }}</div></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @if ($canAssign)
                <div class="bulk-bar mt">
                    <span class="hint">Tick people to reassign the course so they do it again.</span>
                    <input type="date" name="due_on" aria-label="New due date" min="{{ today()->toDateString() }}" style="max-width:180px">
                    <input type="text" name="reason" aria-label="Note" placeholder="Note (optional)" maxlength="255" style="max-width:240px">
                    <button class="btn btn-small" type="submit" onclick="return confirm('Reassign to the people ticked? Their current results stay as history.')">Reassign</button>
                </div>
            @endif
        </form>
        @endif
    </div>

    @if ($history->isNotEmpty())
        <div class="card">
            <h2>Earlier times</h2>
            <div class="table-wrap"><table>
                <thead><tr><th>Team Member</th><th>Assigned</th><th>Result</th><th class="num">Time</th><th class="num">Grade</th><th>Reassigned</th></tr></thead>
                <tbody>
                @foreach ($history as $a)
                    <tr>
                        <td><a href="{{ route('training.person', $a->user) }}">{{ $a->user->name }}</a></td>
                        <td>{{ $a->created_at->format('M j, Y') }}</td>
                        <td>{{ $a->completed_at ? 'Completed '.$a->completed_at->format('M j, Y') : $a->percent.'% done' }}</td>
                        <td class="num">{{ $a->timeLabel() }}</td>
                        <td class="num">{{ $a->score !== null ? $a->score.'%' : '—' }}</td>
                        <td>{{ $a->replaced_at->format('M j, Y') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
    @endif
</div>
@if ($canAssign)
<script>
(function () {
    var all = document.getElementById('all'); if (!all) return;
    var picks = [].slice.call(document.querySelectorAll('#reassign .pick'));
    all.addEventListener('change', function () { picks.forEach(function (p) { p.checked = all.checked; }); });
})();
</script>
@endif
@endsection
