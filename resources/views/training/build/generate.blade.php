@extends('layouts.app', ['title' => 'Write a course with AI'])

@section('band')
    <p style="margin:0 0 .3em"><a style="color:#fff" href="{{ route('training.courses') }}">Courses</a></p>
    <h1>Write a course with AI</h1>
    <p>Tell Claude what the course is about, and add your own policy or handbook if you have it. Claude writes the lesson pages and a quiz as a draft course for you to review, edit and publish.</p>
@endsection

@section('content')
<div class="stack">
    @unless ($ready)
        <div class="flash flash-error">This needs an Anthropic API key. An admin can add it in Admin &gt; Settings.</div>
    @endunless
    <form method="post" action="{{ route('training.generate.store') }}" enctype="multipart/form-data" class="card">@csrf
        <div class="field"><label for="topic">What's the course about?</label><input id="topic" type="text" name="topic" value="{{ old('topic') }}" required maxlength="255" placeholder="Safe patient lifting and stair chair use"></div>
        <div class="field"><label for="audience">Who takes it?</label><input id="audience" type="text" name="audience" value="{{ old('audience') }}" maxlength="500" placeholder="New EMTs and drivers"></div>
        <div class="field"><label for="goals">What should they be able to do afterwards?</label><textarea id="goals" name="goals" rows="3" maxlength="3000" placeholder="Use the stair chair safely, know when to call for a lift assist, protect their back">{{ old('goals') }}</textarea></div>
        <div class="field"><label for="sections">Sections <span class="hint">(optional, in order, separated by commas)</span></label><input id="sections" type="text" name="sections" value="{{ old('sections') }}" maxlength="1000" placeholder="Regulations, Policies, Procedures, Risks"><div class="hint">Leave blank and Claude groups the pages into sections when the course is long enough.</div></div>
        <div class="field"><label for="details">Anything it must cover <span class="hint">(our rules, steps, phone numbers, examples; paste policy text here)</span></label><textarea id="details" name="details" rows="6" maxlength="60000">{{ old('details') }}</textarea></div>
        <div class="field">
            <label for="source">Build it from a document <span class="hint">(optional)</span></label>
            <input id="source" type="file" name="source" accept=".pdf,.txt,.md,.csv">
            <div class="hint">A PDF or text file up to 30 MB, such as the handbook section or SOP. Claude keeps its rules and wording. Save Word and PowerPoint files as PDF first.</div>
            <label class="check mt"><input type="checkbox" name="add_source_lesson" value="1" @checked(old('add_source_lesson', true))> Also add the PDF itself as the first lesson</label>
        </div>
        <div class="field-row">
            <div class="field"><label for="lessons">Lesson pages</label><input id="lessons" type="number" name="lessons" min="1" max="12" value="{{ old('lessons') }}" placeholder="Claude decides"></div>
            <div class="field"><label for="questions">Quiz questions</label><input id="questions" type="number" name="questions" min="0" max="40" value="{{ old('questions', 10) }}"><div class="hint">0 for no quiz.</div></div>
            <div class="field"><label for="pass">Passing score (%)</label><input id="pass" type="number" name="passing_score" min="1" max="100" value="{{ old('passing_score', 80) }}" required></div>
        </div>
        <button class="btn" type="submit" @disabled(! $ready)>Write the course</button>
        <p class="hint mt">It takes a few minutes. You can leave this page; the draft shows up under Courses when it's done. Always read it over before publishing: AI can get things wrong, and anything it couldn't know is marked "Fast Track EMS to confirm".</p>
    </form>

    @if ($generations->isNotEmpty())
        <div class="card">
            <h2>Recent requests</h2>
            <div class="table-wrap"><table>
                <thead><tr><th>Topic</th><th>Status</th><th>Course</th><th>Asked by</th><th>When</th></tr></thead>
                <tbody>
                @foreach ($generations as $g)
                    <tr>
                        <td><a href="{{ route('training.generate.show', $g) }}">{{ $g->topic }}</a>@if ($g->source_name)<div class="hint">From {{ $g->source_name }}</div>@endif</td>
                        <td><span @class(['pill', 'pill-green' => $g->status === 'done', 'pill-red' => $g->status === 'failed', 'pill-teal' => $g->isOpen()])>{{ $g->statusLabel() }}</span></td>
                        <td>@if ($g->course)<a href="{{ route('training.courses.edit', $g->course) }}">{{ $g->course->title }}</a>@else — @endif</td>
                        <td>{{ $g->requester?->name }}</td>
                        <td>{{ $g->created_at->format('M j, g:i A') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
    @endif
</div>
@endsection
