@extends('layouts.app', ['title' => $person->name.' training'])

@section('band')
    <h1>{{ $person->name }}</h1>
    <p>{{ $person->profile?->label() }} · {{ $assignments->whereNull('replaced_at')->whereNotNull('completed_at')->count() }} of {{ $assignments->whereNull('replaced_at')->count() }} courses completed</p>
@endsection

@section('content')
<div class="stack">
    @forelse ($assignments as $a)
        <div class="card">
            <div class="row-actions" style="justify-content:space-between">
                <h2 style="margin:0"><a href="{{ route('training.course', $a) }}">{{ $a->course->title }}</a></h2>
                @include('training.partials.status', ['assignment' => $a])
            </div>
            <div class="mt">@include('training.partials.progress', ['percent' => $a->percent])</div>
            <p class="hint mt">
                {{ $a->percent }}% done · {{ $a->timeLabel() }} spent · grade {{ $a->score !== null ? $a->score.'%' : '—' }}<br>
                Assigned {{ $a->created_at->format('M j, Y') }} by {{ $a->assigner?->name ?? 'automatic assignment' }}@if ($a->trainingProfile) ({{ $a->trainingProfile->name }})@endif
                @if ($a->reason) · {{ $a->reason }}@endif
                @if ($a->started_at) · started {{ $a->started_at->format('M j, Y') }}@endif
                @if ($a->completed_at) · completed {{ $a->completed_at->format('M j, Y g:i A') }}@endif
                @if ($a->replaced_at) · reassigned {{ $a->replaced_at->format('M j, Y') }}@endif
            </p>
            @if ($a->attempts->whereNotNull('submitted_at')->isNotEmpty())
                <div class="table-wrap"><table>
                    <thead><tr><th>Quiz</th><th>Attempt</th><th class="num">Score</th><th>Result</th><th>Date</th><th class="num">Time</th></tr></thead>
                    <tbody>
                    @foreach ($a->attempts->whereNotNull('submitted_at') as $t)
                        <tr>
                            <td>{{ $t->lesson?->title }}</td>
                            <td><a href="{{ route('training.attempt', $t) }}">{{ $t->number }}</a></td>
                            <td class="num">{{ $t->score }}%</td>
                            <td>@if ($t->passed)<span class="pill pill-green">Passed</span>@else<span class="pill pill-red">Not passed</span>@endif</td>
                            <td>{{ $t->submitted_at->format('M j, Y g:i A') }}</td>
                            <td class="num">{{ \App\Training\Models\Assignment::duration($t->seconds) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
            @if ($canAssign && $a->isOpen())
                <div class="row-actions mt">
                    <form method="post" action="{{ route('training.assignments.update', $a) }}" class="row-actions">@csrf @method('put')
                        <input type="date" name="due_on" value="{{ $a->due_on?->toDateString() }}" aria-label="Due date" style="max-width:180px">
                        <button class="btn btn-small btn-outline" type="submit">Save due date</button>
                    </form>
                    @foreach ($a->course->lessons->where('type', 'quiz')->whereNotNull('max_attempts') as $quiz)
                        <form method="post" action="{{ route('training.assignments.extra', $a) }}">@csrf
                            <input type="hidden" name="lesson_id" value="{{ $quiz->id }}">
                            <button class="btn btn-small btn-outline" type="submit">Allow another attempt{{ $a->course->lessons->where('type', 'quiz')->count() > 1 ? ' at '.$quiz->title : '' }}</button>
                        </form>
                    @endforeach
                    <form method="post" action="{{ route('training.assignments.destroy', $a) }}" onsubmit="return confirm('Unassign {{ addslashes($a->course->title) }}? Their progress on it is removed.')">@csrf @method('delete')
                        <button class="btn btn-small btn-outline" type="submit">Unassign</button>
                    </form>
                </div>
            @endif
            @if ($canAssign && ! $a->replaced_at && $a->completed_at)
                <form method="post" action="{{ route('training.assignments.reassign') }}" class="mt">@csrf
                    <input type="hidden" name="assignment_ids[]" value="{{ $a->id }}">
                    <button class="btn btn-small btn-outline" type="submit" onclick="return confirm('Reassign {{ addslashes($a->course->title) }} so {{ addslashes($person->name) }} does it again?')">Reassign to do again</button>
                </form>
            @endif
        </div>
    @empty
        <div class="card"><p class="empty">No training assigned.</p></div>
    @endforelse
</div>
@endsection
