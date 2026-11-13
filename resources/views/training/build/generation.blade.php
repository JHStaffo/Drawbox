@extends('layouts.app', ['title' => $generation->topic])

@section('band')
    <p style="margin:0 0 .3em"><a style="color:#fff" href="{{ route('training.courses') }}">Courses</a> · <a style="color:#fff" href="{{ route('training.generate') }}">Write a course with AI</a></p>
    <h1>{{ $generation->topic }}</h1>
    <p>Asked by {{ $generation->requester?->name ?? 'someone' }} {{ $generation->created_at->format('M j, Y g:i A') }}@if ($generation->source_name) · from {{ $generation->source_name }}@endif</p>
@endsection

@if ($generation->isOpen())
    @push('scripts')<script>setTimeout(function () { location.reload(); }, 10000);</script>@endpush
@endif

@section('content')
<div class="stack">
    <div class="card">
        <p><span @class(['pill', 'pill-green' => $generation->status === 'done', 'pill-red' => $generation->status === 'failed', 'pill-teal' => $generation->isOpen()])>{{ $generation->statusLabel() }}</span></p>
        @if ($generation->isOpen())
            <p>Claude is {{ $generation->status === 'queued' ? 'about to start' : 'writing the course' }}. It usually takes a few minutes, and this page checks every 10 seconds.</p>
            @if ($generation->error)<p class="hint">Last try: {{ $generation->error }}</p>@endif
        @elseif ($generation->status === 'failed')
            <p class="error">{{ $generation->error }}</p>
            <form method="post" action="{{ route('training.generate.retry', $generation) }}">@csrf<button class="btn" type="submit">Try again</button></form>
        @elseif ($generation->course)
            @php($course = $generation->course)
            <h2>{{ $course->title }}</h2>
            @if ($course->description)<p>{{ $course->description }}</p>@endif
            <ol>
                @foreach ($course->lessons as $l)<li>{{ $l->title }} <span class="hint">{{ $l->typeLabel() }}@if ($l->isQuiz()) · {{ $l->questions()->count() }} questions @endif</span></li>@endforeach
            </ol>
            @if ($generation->notes)
                <div class="flash" style="white-space:pre-line;background:#fff4d6;color:#5c4300"><strong>To check before publishing:</strong> {{ $generation->notes }}</div>
            @endif
            <div class="row-actions">
                <a class="btn" href="{{ route('training.courses.edit', $course) }}">Review and edit the course</a>
                @if ($course->lessons->first())<a class="btn btn-outline" href="{{ route('training.lessons.preview', $course->lessons->first()) }}">Preview it</a>@endif
            </div>
            <p class="hint mt">It's a draft. Read every page and the quiz, fix anything that isn't right for Fast Track EMS, then set it to Published.</p>
        @else
            <p>The course it wrote has since been deleted.</p>
        @endif
        @if ($generation->input_tokens || $generation->output_tokens)
            <p class="hint">Claude API use: {{ number_format($generation->input_tokens) }} tokens in, {{ number_format($generation->output_tokens) }} out.</p>
        @endif
    </div>
    @unless ($generation->isOpen())
        <form method="post" action="{{ route('training.generate.destroy', $generation) }}" onsubmit="return confirm('Remove this request? A course it made stays under Courses.')">@csrf @method('delete')
            <button class="btn btn-small btn-outline" type="submit">Remove this request</button>
        </form>
    @endunless
</div>
@endsection
