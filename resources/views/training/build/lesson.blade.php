@extends('layouts.app', ['title' => $lesson->title])

@section('band')
    <p style="margin:0 0 .3em"><a style="color:#fff" href="{{ route('training.courses') }}">Courses</a> · <a style="color:#fff" href="{{ route('training.courses.edit', $course) }}">{{ $course->title }}</a></p>
    <h1>{{ $lesson->title }}</h1>
    <p>{{ $lesson->typeLabel() }}</p>
@endsection


@section('content')
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<div class="stack">
    <form method="post" action="{{ route('training.lessons.update', $lesson) }}" enctype="multipart/form-data" class="card" id="lesson-form">@csrf @method('put')
        <div class="field"><label for="l-title">Title</label><input id="l-title" type="text" name="title" value="{{ old('title', $lesson->title) }}" required maxlength="255"></div>

        @if ($lesson->hasFile())
            <div class="field">
                <label for="upload">{{ $lesson->file_path ? 'Replace the file' : 'File' }}</label>
                @if ($lesson->file_path)<p class="hint">Now: <a href="{{ route('training.file', $lesson) }}" target="_blank" rel="noopener">{{ $lesson->file_name }}</a> ({{ $lesson->fileSizeLabel() }})</p>@endif
                <input id="upload" type="file" name="upload" accept="{{ collect(\App\Training\Models\Lesson::FILE_TYPES[$lesson->type])->map(fn ($e) => '.'.$e)->implode(',') }}" @required(! $lesson->file_path)>
                <div class="hint">
                    {{ strtoupper(implode(', ', \App\Training\Models\Lesson::FILE_TYPES[$lesson->type])) }}, up to {{ $serverLimitMb }} MB.
                    @if ($lesson->type === 'slides') Slides show in Microsoft's free viewer (up to 10 MB); bigger decks are offered as a download, so a PDF of the deck shows best. @endif
                    @if ($lesson->type === 'video') MP4 plays everywhere, including iPhones. @endif
                </div>
            </div>
        @endif

        @if ($lesson->type === 'link')
            <div class="field"><label for="l-url">Link</label><input id="l-url" type="url" name="url" value="{{ old('url', $lesson->url) }}" required maxlength="1000" placeholder="https://www.youtube.com/watch?v=..."><div class="hint">YouTube, Vimeo and Loom links play inside the lesson. Other sites open in a new tab.</div></div>
        @endif

        @if ($lesson->isQuiz())
            <div class="field-row">
                <div class="field"><label for="pass">Passing score (%)</label><input id="pass" type="number" name="passing_score" min="1" max="100" required value="{{ old('passing_score', $lesson->passing()) }}"></div>
                <div class="field"><label for="max">Attempts allowed</label><input id="max" type="number" name="max_attempts" min="1" max="99" value="{{ old('max_attempts', $lesson->max_attempts) }}" placeholder="No limit"><div class="hint">A trainer can allow more for one person later.</div></div>
            </div>
            <label class="check"><input type="checkbox" name="show_answers" value="1" @checked(old('show_answers', $lesson->show_answers))> Show the right answers after each attempt</label>
            <label class="check"><input type="checkbox" name="shuffle" value="1" @checked(old('shuffle', $lesson->shuffle))> Shuffle the questions for each attempt</label>
        @else
            <div class="field"><label for="min">Minimum time on this lesson (minutes)</label><input id="min" type="number" name="min_minutes" min="0" max="600" style="max-width:200px" value="{{ old('min_minutes', $lesson->min_seconds ? intdiv($lesson->min_seconds, 60) : '') }}" placeholder="None"><div class="hint">"Mark as done" waits until they've spent this long on it.</div></div>
        @endif

        <div class="field mt">
            <label>{{ $lesson->type === 'text' ? 'Page' : ($lesson->isQuiz() ? 'Instructions (optional)' : 'Text above the '.strtolower($lesson->typeLabel()).' (optional)') }}</label>
            <div @class(['designer', 'designer-short' => $lesson->type !== 'text'])><div id="designer">{!! old('body', $lesson->body) !!}</div></div>
            <input type="hidden" name="body" id="body" value="{{ old('body', $lesson->body) }}">
            <div class="hint">Type and format like a word processor. Paste or drop pictures right in, or use the picture button.</div>
        </div>

        <div class="row-actions">
            <button class="btn" type="submit">Save</button>
            <a class="btn btn-outline" href="{{ route('training.lessons.preview', $lesson) }}">Preview</a>
            <a class="btn btn-outline" href="{{ route('training.courses.edit', $course) }}">Back to the course</a>
        </div>
    </form>

    @if ($lesson->isQuiz())
        <div class="card">
            <h2>Questions</h2>
            @forelse ($lesson->questions as $i => $q)
                <details class="quiz-q" id="q{{ $q->id }}">
                    <summary><strong>{{ $i + 1 }}.</strong> {{ Str::limit($q->prompt, 140) }} <span class="hint">· {{ $q->typeLabel() }} · right: {{ collect($q->choices)->where('correct', true)->pluck('text')->implode(', ') }}</span></summary>
                    <form method="post" action="{{ route('training.questions.update', $q) }}" class="mt q-form">@csrf @method('put')
                        @include('training.build.question-fields', ['q' => $q, 'key' => $q->id])
                        <button class="btn btn-small" type="submit">Save question</button>
                    </form>
                    <div class="row-actions mt">
                        <form method="post" action="{{ route('training.questions.move', $q) }}">@csrf<input type="hidden" name="direction" value="up"><button class="btn btn-small btn-outline" type="submit" @disabled($i === 0)>Move up</button></form>
                        <form method="post" action="{{ route('training.questions.move', $q) }}">@csrf<input type="hidden" name="direction" value="down"><button class="btn btn-small btn-outline" type="submit" @disabled($loop->last)>Move down</button></form>
                        <form method="post" action="{{ route('training.questions.destroy', $q) }}" onsubmit="return confirm('Remove this question?')">@csrf @method('delete')<button class="btn btn-small btn-outline" type="submit">Remove</button></form>
                    </div>
                </details>
            @empty
                <p class="empty">No questions yet.</p>
            @endforelse

            <h3 class="mt" id="new-question">Add a question</h3>
            <form method="post" action="{{ route('training.questions.store', $lesson) }}" class="q-form">@csrf
                @include('training.build.question-fields', ['q' => null, 'key' => 'new'])
                <button class="btn" type="submit">Add question</button>
            </form>
        </div>
    @endif

    <form method="post" action="{{ route('training.lessons.destroy', $lesson) }}" onsubmit="return confirm('Remove this lesson? Progress on it is removed too.')">@csrf @method('delete')
        <button class="btn btn-small btn-outline" type="submit">Remove this lesson</button>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
(function () {
    // Question forms: show the answer boxes that fit the kind of question.
    document.querySelectorAll('.q-form').forEach(function (form) {
        var type = form.querySelector('.q-type');
        function show() {
            form.querySelector('.q-choices').hidden = type.value === 'true_false';
            form.querySelector('.q-tf').hidden = type.value !== 'true_false';
        }
        type.addEventListener('change', show);
    });

    // The course designer (Quill). Pictures are uploaded and linked, never stored inside the page.
    if (!window.Quill) return;
    var token = @json(csrf_token()), uploadUrl = @json(route('training.images.store'));
    var holder = document.getElementById('designer'), hidden = document.getElementById('body'), form = document.getElementById('lesson-form');
    var quill = new Quill(holder, {
        theme: 'snow',
        placeholder: 'Write the lesson here...',
        modules: { toolbar: { container: [
            [{ header: [1, 2, 3, false] }, { size: ['small', false, 'large', 'huge'] }],
            ['bold', 'italic', 'underline', 'strike'], [{ color: [] }, { background: [] }],
            [{ list: 'ordered' }, { list: 'bullet' }, { indent: '-1' }, { indent: '+1' }, { align: [] }],
            ['blockquote', 'link', 'image', 'video'], ['clean']
        ], handlers: { image: pickImage } } }
    });

    function upload(file) {
        var body = new FormData(); body.append('_token', token); body.append('image', file);
        holder.classList.add('designer-uploading');
        return fetch(uploadUrl, { method: 'POST', body: body, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'Upload failed'); return j.url; }); })
            .finally(function () { holder.classList.remove('designer-uploading'); });
    }
    function pickImage() {
        var input = document.createElement('input'); input.type = 'file'; input.accept = 'image/jpeg,image/png,image/gif,image/webp';
        input.onchange = function () {
            if (!input.files[0]) return;
            var at = (quill.getSelection(true) || { index: quill.getLength() }).index;
            upload(input.files[0]).then(function (url) { quill.insertEmbed(at, 'image', url, 'user'); quill.setSelection(at + 1); })
                .catch(function (e) { alert(e.message); });
        };
        input.click();
    }
    // Pasted or dropped pictures arrive as data: addresses; swap each for an uploaded copy.
    quill.on('text-change', function () {
        quill.root.querySelectorAll('img[src^="data:"]:not([data-uploading])').forEach(function (img) {
            img.setAttribute('data-uploading', '1');
            fetch(img.src).then(function (r) { return r.blob(); })
                .then(function (blob) { return upload(new File([blob], 'pasted.' + (blob.type.split('/')[1] || 'png'), { type: blob.type })); })
                .then(function (url) { img.src = url; img.removeAttribute('data-uploading'); })
                .catch(function () { img.remove(); });
        });
    });
    form.addEventListener('submit', function (e) {
        if (holder.classList.contains('designer-uploading')) { e.preventDefault(); alert('A picture is still uploading. Try Save again in a moment.'); return; }
        hidden.value = quill.getLength() > 1 || quill.root.querySelector('img,iframe') ? quill.root.innerHTML : '';
    });
})();
</script>
@endsection
