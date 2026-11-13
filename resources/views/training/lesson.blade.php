@extends('layouts.app', ['title' => $lesson->title])

@section('band')
    <p style="margin:0 0 .3em">@if ($preview)<a style="color:#fff" href="{{ route('training.courses.edit', $course) }}">{{ $course->title }}</a> · Preview @else<a style="color:#fff" href="{{ route('training.course', $assignment) }}">{{ $course->title }}</a>@endif @if ($lesson->section) · {{ $lesson->section->title }}@endif · Lesson {{ $number }} of {{ $count }}</p>
    <h1>{{ $lesson->title }}</h1>
@endsection

@section('content')
@php($fileUrl = route('training.file', $lesson))
@php($done = $progress?->completed_at)
<div class="stack">
    @if ($preview)
        <div class="flash flash-ok">Preview: this is what Team Members see. Time isn't counted and quiz answers aren't saved.</div>
    @endif
    <div class="card">
        @if ($lesson->isQuiz())
            @if ($lesson->body)<div class="rich">{!! $lesson->body !!}</div>@endif
            <p>{{ $lesson->questions()->count() }} {{ Str::plural('question', $lesson->questions()->count()) }} · pass with {{ $lesson->passing() }}% · {{ $lesson->max_attempts ? $lesson->max_attempts.' '.Str::plural('attempt', $lesson->max_attempts).' allowed' : 'as many attempts as needed' }}</p>
            @if ($preview)
                @foreach ($lesson->questions as $i => $q)
                    <div class="quiz-q">
                        <div class="quiz-prompt">{{ $i + 1 }}. {{ $q->prompt }}</div>
                        <div class="hint">{{ $q->typeLabel() }}</div>
                        @foreach ($q->choices as $c)
                            <div @class(['quiz-choice', 'quiz-right' => $c['correct']])>{{ $c['correct'] ? '✓' : '○' }} {{ $c['text'] }}</div>
                        @endforeach
                    </div>
                @endforeach
            @else
                @if ($attempts->whereNotNull('submitted_at')->isNotEmpty())
                    <div class="table-wrap"><table>
                        <thead><tr><th>Attempt</th><th class="num">Score</th><th>Result</th><th>Date</th><th class="num">Time</th></tr></thead>
                        <tbody>
                        @foreach ($attempts->whereNotNull('submitted_at') as $t)
                            <tr>
                                <td><a href="{{ route('training.attempt', $t) }}">Attempt {{ $t->number }}</a></td>
                                <td class="num">{{ $t->score }}%</td>
                                <td>@if ($t->passed)<span class="pill pill-green">Passed</span>@else<span class="pill pill-red">Not passed</span>@endif</td>
                                <td>{{ $t->submitted_at->format('M j, Y g:i A') }}</td>
                                <td class="num">{{ \App\Training\Models\Assignment::duration($t->seconds) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
                @if ($attemptInfo['allowed'])<p class="hint mt">{{ $attemptInfo['used'] }} of {{ $attemptInfo['allowed'] }} attempts used.</p>@endif
                @if ($mine)
                    @if ($done)
                        <p class="mt"><span class="pill pill-green">Passed with {{ $progress->best_score }}%</span></p>
                    @elseif ($cantAttempt)
                        <p class="error mt">{{ $cantAttempt }}</p>
                    @else
                        @php($inProgress = $attempts->whereNull('submitted_at')->isNotEmpty())
                        <form method="post" action="{{ route('training.attempts.start', [$assignment, $lesson]) }}" class="mt">@csrf
                            <button class="btn" type="submit">{{ $inProgress ? 'Go back to my attempt' : ($attempts->isEmpty() ? 'Start the quiz' : 'Try again') }}</button>
                        </form>
                    @endif
                @endif
            @endif
        @else
            @include('training.partials.content')
            @if ($mine && $assignment->isOpen())
                <div class="row-actions mt">
                    @if ($done)
                        <span class="pill pill-green">Done</span>
                    @else
                        <form method="post" action="{{ route('training.done', [$assignment, $lesson]) }}">@csrf
                            <button class="btn" type="submit" id="mark-done" @disabled($cantFinish)>Mark as done</button>
                        </form>
                        @if ($cantFinish)<span class="hint" id="done-hint" data-min="{{ $lesson->min_seconds }}" data-spent="{{ $progress?->seconds_spent ?? 0 }}">{{ $cantFinish }}</span>@endif
                    @endif
                </div>
            @elseif ($done)
                <p class="mt"><span class="pill pill-green">Done {{ $done->format('M j, Y') }}</span></p>
            @endif
        @endif
    </div>

    <div class="lesson-nav">
        @if ($previous)
            <a class="btn btn-outline btn-small" href="{{ $preview ? route('training.lessons.preview', $previous) : route('training.lesson', [$assignment, $previous]) }}">← {{ $previous->title }}</a>
        @else <span></span> @endif
        @if ($next)
            <a class="btn btn-outline btn-small" href="{{ $preview ? route('training.lessons.preview', $next) : route('training.lesson', [$assignment, $next]) }}">{{ $next->title }} →</a>
        @endif
    </div>
</div>

@if ($mine && $assignment->isOpen() && ! $lesson->isQuiz())
<script>
// Counts time while this page is in front and in use (or a video is playing), and
// reports it every 15 seconds. Ten minutes without a click, key, scroll or touch stops the count.
(function () {
    var url = @json(route('training.time', [$assignment, $lesson])), token = @json(csrf_token());
    var pending = 0, lastInput = Date.now(), IDLE = 10 * 60 * 1000;
    var media = [].slice.call(document.querySelectorAll('[data-media]'));
    var hint = document.getElementById('done-hint'), button = document.getElementById('mark-done');
    var min = hint ? +hint.dataset.min : 0, spent = hint ? +hint.dataset.spent : 0;
    ['mousemove', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (e) { window.addEventListener(e, function () { lastInput = Date.now(); }, { passive: true }); });
    function playing() { return media.some(function (m) { return !m.paused && !m.ended; }); }
    function active() { return document.visibilityState === 'visible' && (playing() || Date.now() - lastInput < IDLE); }
    function send(beacon) {
        if (pending < 1) return;
        var body = new FormData(); body.append('_token', token); body.append('seconds', pending);
        spent += pending; pending = 0;
        if (beacon && navigator.sendBeacon) { navigator.sendBeacon(url, body); return; }
        fetch(url, { method: 'POST', body: body, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }).catch(function () {});
    }
    setInterval(function () {
        if (active()) pending++;
        if (min && button && button.disabled && !button.dataset.unlocking && spent + pending >= min) {
            button.dataset.unlocking = '1'; send(false);
            setTimeout(function () { button.disabled = false; hint.textContent = ''; }, 2000);
        }
    }, 1000);
    setInterval(function () { send(false); }, 15000);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') send(true); });
    window.addEventListener('pagehide', function () { send(true); });
})();
</script>
@endif
@endsection
