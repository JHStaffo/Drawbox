@extends('layouts.app', ['title' => $lesson->title])

@section('band')
    <p style="margin:0 0 .3em"><a style="color:#fff" href="{{ route('training.course', $assignment) }}">{{ $assignment->course->title }}</a> · {{ $lesson->title }}@unless ($mine) · {{ $assignment->user->name }}@endunless</p>
    <h1>{{ $attempt->isSubmitted() ? 'Attempt '.$attempt->number.' results' : 'Attempt '.$attempt->number }}</h1>
@endsection

@section('content')
<div class="stack">
@if (! $attempt->isSubmitted())
    <form method="post" action="{{ route('training.attempts.submit', $attempt) }}" id="quiz">@csrf
        <div class="card">
            <p class="hint">Answer every question, then press Submit. You need {{ $lesson->passing() }}% to pass.</p>
            @foreach ($questions as $i => $q)
                <div class="quiz-q">
                    <fieldset>
                        <legend>{{ $i + 1 }}. {{ $q->prompt }}</legend>
                        @if ($q->type === 'multiple')<div class="hint">Select all that apply.</div>@endif
                        @foreach ($q->choices as $ci => $c)
                            <label class="quiz-choice">
                                @if ($q->type === 'multiple')
                                    <input type="checkbox" name="answers[{{ $q->id }}][]" value="{{ $ci }}">
                                @else
                                    <input type="radio" name="answers[{{ $q->id }}]" value="{{ $ci }}">
                                @endif
                                <span>{{ $c['text'] }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                </div>
            @endforeach
            <button class="btn btn-big mt" type="submit">Submit</button>
        </div>
    </form>
    <script>
    document.getElementById('quiz').addEventListener('submit', function (e) {
        var groups = {}, missing = 0;
        [].forEach.call(this.querySelectorAll('input[name^="answers"]'), function (i) { var n = i.name.replace('[]', ''); groups[n] = groups[n] || i.checked; });
        for (var k in groups) if (!groups[k]) missing++;
        if (missing && !confirm(missing + (missing === 1 ? ' question is' : ' questions are') + ' not answered. Submit anyway?')) e.preventDefault();
    });
    </script>
@else
    <div class="card">
        <div class="row-actions" style="gap:20px">
            <div class="big-score" style="color: {{ $attempt->passed ? 'var(--green)' : 'var(--red)' }}">{{ $attempt->score }}%</div>
            <div>
                @if ($attempt->passed)<span class="pill pill-green">Passed</span>@else<span class="pill pill-red">Not passed</span>@endif
                <div class="hint">{{ $attempt->correct }} of {{ $attempt->total }} right · pass with {{ $lesson->passing() }}% · {{ \App\Training\Models\Assignment::duration($attempt->seconds) }} · {{ $attempt->submitted_at->format('M j, Y g:i A') }}</div>
            </div>
        </div>
        <div class="row-actions mt">
            @if ($mine && ! $cantAttempt)
                <form method="post" action="{{ route('training.attempts.start', [$assignment, $lesson]) }}">@csrf<button class="btn" type="submit">Try again</button></form>
            @elseif ($mine && ! $attempt->passed && $assignment->isOpen())
                <span class="error">{{ $cantAttempt }}</span>
            @endif
            <a class="btn btn-outline" href="{{ route('training.course', $assignment) }}">Back to the course</a>
        </div>
    </div>
    <div class="card">
        <h2>Questions</h2>
        @foreach ($attempt->answers ?? [] as $i => $a)
            <div @class(['quiz-result-q', 'qr-right' => $a['right'], 'qr-wrong' => ! $a['right']])>
                <div class="quiz-prompt"><strong>{{ $i + 1 }}. {{ $a['prompt'] }}</strong> {{ $a['right'] ? '✓' : '✗' }}</div>
                @foreach ($a['choices'] as $ci => $c)
                    @php($picked = in_array($ci, $a['chosen'], true))
                    @if ($reveal)
                        <div @class(['quiz-choice', 'quiz-right' => $c['correct'], 'quiz-wrong' => $picked && ! $c['correct']])>{{ $picked ? '●' : '○' }} {{ $c['text'] }} @if ($c['correct'])<span class="hint">(right answer)</span>@endif</div>
                    @elseif ($picked)
                        <div class="quiz-choice">● {{ $c['text'] }}</div>
                    @endif
                @endforeach
                @if ($reveal && $a['explanation'])<div class="hint">{{ $a['explanation'] }}</div>@endif
            </div>
        @endforeach
    </div>
@endif
</div>
@endsection
