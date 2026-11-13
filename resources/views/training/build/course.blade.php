@extends('layouts.app', ['title' => $course->title])

@section('band')
    <p style="margin:0 0 .3em"><a style="color:#fff" href="{{ route('training.courses') }}">Courses</a></p>
    <h1>{{ $course->title }}</h1>
    <p>{{ $course->statusLabel() }} · {{ $course->lessons->count() }} {{ Str::plural('lesson', $course->lessons->count()) }} · assigned to {{ $assigned }}</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <h2>Lessons</h2>
        @if ($course->lessons->isEmpty())
            <p class="empty">No lessons yet. Add the first one below.</p>
        @else
            <ol class="lesson-list">
                @foreach ($course->lessons as $i => $l)
                    <li>
                        <span class="lesson-mark">{{ $i + 1 }}</span>
                        <div class="lesson-main">
                            <a href="{{ route('training.lessons.edit', $l) }}">{{ $l->title }}</a>
                            <div class="hint">{{ $l->typeLabel() }}
                                @if ($l->isQuiz()) · {{ $questionCounts[$l->id] ?? 0 }} {{ Str::plural('question', $questionCounts[$l->id] ?? 0) }} · pass {{ $l->passing() }}%@endif
                                @if ($l->hasFile()) · {{ $l->file_name ?: 'no file yet' }}@endif
                                @if ($l->min_seconds) · at least {{ \App\Training\Models\Assignment::duration($l->min_seconds) }}@endif
                            </div>
                        </div>
                        <div class="row-actions">
                            <a class="btn btn-small btn-outline" href="{{ route('training.lessons.preview', $l) }}">Preview</a>
                            <form method="post" action="{{ route('training.lessons.move', $l) }}">@csrf<input type="hidden" name="direction" value="up"><button class="btn btn-small btn-outline" type="submit" @disabled($i === 0) aria-label="Move up">↑</button></form>
                            <form method="post" action="{{ route('training.lessons.move', $l) }}">@csrf<input type="hidden" name="direction" value="down"><button class="btn btn-small btn-outline" type="submit" @disabled($i === $course->lessons->count() - 1) aria-label="Move down">↓</button></form>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
        <form method="post" action="{{ route('training.lessons.store', $course) }}" class="field-row mt" style="align-items:end">@csrf
            <div class="field"><label for="lesson-type">Add a lesson</label>
                <select id="lesson-type" name="type">@foreach (\App\Training\Models\Lesson::TYPES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>
            </div>
            <div class="field" style="grid-column: span 2"><label for="lesson-title">Title</label><input id="lesson-title" type="text" name="title" required maxlength="255" placeholder="Section 1: Our mission"></div>
            <div class="field"><button class="btn" type="submit">Add</button></div>
        </form>
    </div>

    <div class="card">
        <h2>Course details</h2>
        <form method="post" action="{{ route('training.courses.update', $course) }}">@csrf @method('put')
            <div class="field"><label for="c-title">Title</label><input id="c-title" type="text" name="title" value="{{ old('title', $course->title) }}" required maxlength="255"></div>
            <div class="field"><label for="c-desc">Description</label><textarea id="c-desc" name="description" rows="3" maxlength="5000">{{ old('description', $course->description) }}</textarea></div>
            <div class="field-row">
                <div class="field"><label for="c-cat">Category</label><input id="c-cat" type="text" name="category" value="{{ old('category', $course->category) }}" maxlength="80" list="categories" placeholder="Handbook, Clinical, Driving">
                    <datalist id="categories">@foreach ($categories as $cat)<option value="{{ $cat }}">@endforeach</datalist></div>
                <div class="field"><label for="c-due">Days to finish</label><input id="c-due" type="number" name="due_days" min="1" max="3650" value="{{ old('due_days', $course->due_days) }}" placeholder="No due date"><div class="hint">Counted from the day it's assigned.</div></div>
                <div class="field"><label for="c-status">Status</label>
                    <select id="c-status" name="status">@foreach (\App\Training\Models\Course::STATUSES as $k => $label)<option value="{{ $k }}" @selected(old('status', $course->status) === $k)>{{ $label }}</option>@endforeach</select>
                    <div class="hint">Published courses can be assigned. Archived ones can't be assigned again, but people who have them can finish.</div></div>
            </div>
            <div class="row-actions">
                <button class="btn" type="submit">Save</button>
                @if (auth()->user()->hasPermission('training_assign') && $course->isPublished())<a class="btn btn-outline" href="{{ route('training.assign', ['course' => $course->id]) }}">Assign it</a>@endif
                @if ($assigned && auth()->user()->hasAnyPermission('training_assign', 'training_reports'))<a class="btn btn-outline" href="{{ route('training.progress', $course) }}">Progress</a>@endif
            </div>
        </form>
        @unless ($course->assignments()->exists())
            <form method="post" action="{{ route('training.courses.destroy', $course) }}" class="mt" onsubmit="return confirm('Delete {{ addslashes($course->title) }} and all its lessons?')">@csrf @method('delete')
                <button class="btn btn-small btn-outline" type="submit">Delete course</button>
            </form>
        @endunless
    </div>
</div>
@endsection
