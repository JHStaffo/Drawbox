@extends('layouts.app', ['title' => $course->title])

@section('band')
    <p style="margin:0 0 .3em"><a style="color:#fff" href="{{ route('training.courses') }}">Courses</a></p>
    <h1>{{ $course->title }}</h1>
    <p>{{ $course->statusLabel() }}@if ($course->isVersioned()) · version {{ $course->version }}@endif · {{ $course->lessons->count() }} {{ Str::plural('lesson', $course->lessons->count()) }} · {{ $course->estimateLabel() }} · assigned to {{ $assigned }}</p>
@endsection

@section('content')
<div class="stack">
    @if ($pending)
        <form class="card card-pending" method="post" action="{{ route('training.courses.publish', $course) }}">@csrf
            <h2>Unpublished changes ({{ count($pending) }})</h2>
            <p class="hint">Team Members still see version {{ $course->version }}. Publish these together as version {{ $course->version + 1 }} when they're ready.</p>
            <ul>@foreach ($pending as $c)<li><span class="pill">{{ \App\Training\Models\CourseVersion::KINDS[$c['kind']] }}</span> <a href="{{ route('training.lessons.edit', $c['lesson']) }}">{{ $c['title'] }}</a></li>@endforeach</ul>
            <div class="field"><label for="v-note">What changed <span class="hint">(optional, shown to Team Members who finished the course)</span></label><textarea id="v-note" name="note" rows="2" maxlength="2000" placeholder="Updated the cell phone policy to match the new handbook."></textarea></div>
            <div class="row-actions">
                <button class="btn" type="submit" onclick="return confirm('Publish these changes as version {{ $course->version + 1 }}? Team Members see them right away.')">Publish version {{ $course->version + 1 }}</button>
                <a class="btn btn-outline" href="{{ route('training.courses.versions', $course) }}">Version history</a>
            </div>
        </form>
    @elseif ($latest)
        <p class="hint">Version {{ $latest->number }}, published {{ $latest->published_at->format('M j, Y g:i A') }}@if ($latest->publisher) by {{ $latest->publisher->name }}@endif. Edits wait as unpublished changes until you publish them. <a href="{{ route('training.courses.versions', $course) }}">Version history</a></p>
    @endif
    <div class="card">
        <h2>Lessons</h2>
        @php($groups = $course->lessonGroups($working, true))
        @php($n = 0)
        @if ($working->isEmpty() && $course->sections->isEmpty())
            <p class="empty">No lessons yet. Add the first one below.</p>
        @else
            @foreach ($groups as $g)
                @if ($g['section'])
                    @php($s = $g['section'])
                    <div class="section-head" id="s{{ $s->id }}">
                        <form method="post" action="{{ route('training.sections.update', $s) }}" class="section-name">@csrf @method('put')
                            <input type="text" name="title" value="{{ $s->title }}" required maxlength="255" aria-label="Section name">
                            <button class="btn btn-small btn-outline" type="submit">Rename</button>
                        </form>
                        <span class="hint">{{ $g['lessons']->count() }} {{ Str::plural('lesson', $g['lessons']->count()) }} · {{ \App\Training\Estimate::label((int) $g['lessons']->sum('est_seconds')) }}</span>
                        <div class="row-actions">
                            <form method="post" action="{{ route('training.sections.move', $s) }}">@csrf<input type="hidden" name="direction" value="up"><button class="btn btn-small btn-outline" type="submit" @disabled($s->position <= 1) aria-label="Move section up">↑</button></form>
                            <form method="post" action="{{ route('training.sections.move', $s) }}">@csrf<input type="hidden" name="direction" value="down"><button class="btn btn-small btn-outline" type="submit" @disabled($s->position >= $course->sections->count()) aria-label="Move section down">↓</button></form>
                            <form method="post" action="{{ route('training.sections.destroy', $s) }}" onsubmit="return confirm('Remove the section {{ addslashes($s->title) }}? Its lessons stay in the course.')">@csrf @method('delete')<button class="btn btn-small btn-outline" type="submit">Remove</button></form>
                        </div>
                    </div>
                @endif
                @if ($g['lessons']->isEmpty())
                    <p class="hint section-empty">No lessons in this section yet.</p>
                @else
                <ol class="lesson-list" start="{{ $n + 1 }}">
                    @foreach ($g['lessons'] as $j => $l)
                        @php($n++)
                        <li>
                            <span class="lesson-mark">{{ $n }}</span>
                            <div class="lesson-main">
                                <a href="{{ route('training.lessons.edit', $l) }}" @class(['struck' => $l->pending_remove])>{{ $l->title }}</a>
                                @if ($l->draft_of)<span class="pill pill-amber">Changed, not published</span>@elseif ($l->pending_add)<span class="pill pill-amber">New, not published</span>@elseif ($l->pending_remove)<span class="pill pill-red">Removed when published</span>@endif
                                <div class="hint">{{ $l->typeLabel() }} · {{ \App\Training\Estimate::label($l->est_seconds) }}@if ($l->est_basis) ({{ $l->est_basis }})@endif
                                    @if ($l->isQuiz()) · {{ $questionCounts[$l->id] ?? 0 }} {{ Str::plural('question', $questionCounts[$l->id] ?? 0) }} · pass {{ $l->passing() }}%@endif
                                    @if ($l->hasFile()) · {{ $l->file_name ?: 'no file yet' }}@endif
                                    @if ($l->min_seconds) · at least {{ \App\Training\Models\Assignment::duration($l->min_seconds) }}@endif
                                </div>
                            </div>
                            <div class="row-actions">
                                @if ($l->pending_remove)<form method="post" action="{{ route('training.lessons.keep', $l) }}">@csrf<button class="btn btn-small btn-outline" type="submit">Keep it</button></form>@endif
                                <a class="btn btn-small btn-outline" href="{{ route('training.lessons.preview', $l) }}">Preview</a>
                                <form method="post" action="{{ route('training.lessons.move', $l) }}">@csrf<input type="hidden" name="direction" value="up"><button class="btn btn-small btn-outline" type="submit" @disabled($j === 0) aria-label="Move up">↑</button></form>
                                <form method="post" action="{{ route('training.lessons.move', $l) }}">@csrf<input type="hidden" name="direction" value="down"><button class="btn btn-small btn-outline" type="submit" @disabled($j === $g['lessons']->count() - 1) aria-label="Move down">↓</button></form>
                            </div>
                        </li>
                    @endforeach
                </ol>
                @endif
            @endforeach
            <p class="hint">Expected time is worked out from each lesson's content: 200 words a minute, 2 minutes a PDF page, 1 minute a slide, a video's running time plus 10%, 5 minutes a link, 45 to 75 seconds a quiz question. A minimum time, when longer, wins. To move a lesson to another section, pick the section on its edit page.</p>
        @endif
        <form method="post" action="{{ route('training.lessons.store', $course) }}" class="field-row mt" style="align-items:end">@csrf
            <div class="field"><label for="lesson-type">Add a lesson</label>
                <select id="lesson-type" name="type">@foreach (\App\Training\Models\Lesson::TYPES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>
            </div>
            <div class="field" style="grid-column: span 2"><label for="lesson-title">Title</label><input id="lesson-title" type="text" name="title" required maxlength="255" placeholder="Our mission"></div>
            @if ($course->sections->isNotEmpty())
                <div class="field"><label for="lesson-section">Section</label>
                    <select id="lesson-section" name="section_id"><option value="">No section</option>@foreach ($course->sections as $s)<option value="{{ $s->id }}" @selected($loop->last)>{{ $s->title }}</option>@endforeach</select>
                </div>
            @endif
            <div class="field"><button class="btn" type="submit">Add</button></div>
        </form>
        <form method="post" action="{{ route('training.sections.store', $course) }}" class="field-row mt" style="align-items:end">@csrf
            <div class="field" style="grid-column: span 3"><label for="section-title">Add a section</label><input id="section-title" type="text" name="title" required maxlength="255" placeholder="Regulations, Policies, Procedures, Risks"><div class="hint">Sections group the lessons under headings. They're optional.</div></div>
            <div class="field"><button class="btn btn-outline" type="submit">Add section</button></div>
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
                    <div class="hint">Published courses can be assigned. Archived ones can't be assigned again, but people who have them can finish. @unless ($course->isVersioned())Publishing makes this version 1; after that, lesson edits wait until you publish them.@endunless</div></div>
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
