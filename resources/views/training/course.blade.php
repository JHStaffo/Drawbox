@extends('layouts.app', ['title' => $course->title])

@section('band')
    <h1>{{ $course->title }}</h1>
    <p>@unless ($mine){{ $assignment->user->name }} · @endunless{{ $course->estimateLabel() }} · {{ $assignment->percent }}% done · {{ $assignment->timeLabel() }} spent @if ($assignment->due_on)· due {{ $assignment->due_on->format('M j, Y') }}@endif</p>
@endsection

@section('content')
<div class="stack">
    @if ($assignment->replaced_at)
        <div class="flash flash-error">This course was assigned again on {{ $assignment->replaced_at->format('M j, Y') }}. @if ($mine)Open the new one from <a href="{{ route('training') }}">My Training</a>.@endif</div>
    @endif
    @if ($updatedSince)
        <div class="card version-note">
            <h2>Updated since you finished it</h2>
            <p>You finished version {{ $updatedSince }}. It's now version {{ $course->version }}; lessons marked <span class="pill pill-amber">Updated</span> are new or changed. Your completion still counts.</p>
            @foreach ($updates as $v)
                <p class="mt"><strong>Version {{ $v->number }}, {{ $v->published_at->format('M j, Y') }}</strong>@if ($v->summary()) · {{ $v->summary() }}@endif @if ($v->note)<br>{{ $v->note }}@endif</p>
            @endforeach
        </div>
    @endif
    <div class="card">
        <div class="row-actions" style="justify-content:space-between">
            @include('training.partials.status', ['assignment' => $assignment])
            @if ($assignment->score !== null)<span class="hint">Quiz grade {{ $assignment->score }}%</span>@endif
        </div>
        <div class="mt">@include('training.partials.progress', ['percent' => $assignment->percent])</div>
        @if ($course->description)<p class="mt" style="white-space:pre-line">{{ $course->description }}</p>@endif
        @if ($assignment->reason)<p class="hint">{{ $assignment->reason }}</p>@endif
        @if ($mine && $assignment->isOpen())
            @php($nextLesson = $lessons->first(fn ($l) => ! $l->myProgress?->completed_at))
            @if ($nextLesson)
                <a class="btn mt" href="{{ route('training.lesson', [$assignment, $nextLesson]) }}">{{ $assignment->started_at ? 'Continue' : 'Start' }}: {{ $nextLesson->title }}</a>
            @endif
        @endif
        @if ($assignment->completed_at)<p class="mt"><span class="pill pill-green">Completed {{ $assignment->completed_at->format('M j, Y g:i A') }}</span></p>@endif
    </div>

    <div class="card">
        <h2>Lessons</h2>
        @php($n = 0)
        @foreach ($course->lessonGroups($lessons) as $g)
            @if ($g['section'])
                @php($doneIn = $g['lessons']->filter(fn ($l) => $l->myProgress?->completed_at)->count())
                <div class="section-title"><span>{{ $g['section']->title }}</span><span class="hint">{{ $doneIn }} of {{ $g['lessons']->count() }} done · {{ \App\Training\Estimate::label((int) $g['lessons']->sum('est_seconds')) }}</span></div>
            @endif
            <ol class="lesson-list">
                @foreach ($g['lessons'] as $l)
                    @php($n++)
                    @php($p = $l->myProgress)
                    <li>
                        <span @class(['lesson-mark', 'lesson-mark-done' => $p?->completed_at])>{{ $p?->completed_at ? '✓' : $n }}</span>
                        <div class="lesson-main">
                            <a href="{{ route('training.lesson', [$assignment, $l]) }}">{{ $l->title }}</a>@if ($updatedSince && $l->changed_in > $updatedSince) <span class="pill pill-amber">Updated</span>@endif
                            <div class="hint">{{ $l->typeLabel() }} · {{ \App\Training\Estimate::label($l->est_seconds) }}@if ($p?->seconds_spent) · you: {{ \App\Training\Models\Assignment::duration($p->seconds_spent) }}@endif @if ($l->isQuiz() && $p?->best_score !== null) · best score {{ $p->best_score }}%@endif</div>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endforeach
    </div>
</div>
@endsection
