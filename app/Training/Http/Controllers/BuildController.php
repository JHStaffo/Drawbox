<?php

namespace App\Training\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Training\Models\Assignment;
use App\Training\Models\Course;
use App\Training\Models\Lesson;
use App\Training\Models\Question;
use App\Training\Models\Section;
use App\Training\RichText;
use App\Training\Training;
use App\Training\Versions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Training > Courses: build courses, their lessons and quizzes. */
class BuildController extends Controller
{
    public function index()
    {
        $courses = Course::withCount('lessons')->withSum('lessons as est_total', 'est_seconds')
            ->withCount(['assignments as open_count' => fn ($q) => $q->whereNull('replaced_at')->whereNull('completed_at')])
            ->withCount(['assignments as done_count' => fn ($q) => $q->whereNull('replaced_at')->whereNotNull('completed_at')])
            ->get()
            ->sortBy(fn ($c) => [array_search($c->status, array_keys(Course::STATUSES)), mb_strtolower($c->title)])
            ->values();

        return view('training.build.index', ['courses' => $courses]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedCourse($request);
        $course = Course::create($data + ['status' => 'draft', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        audit_note("Started the course {$course->title} (draft).");

        return redirect()->route('training.courses.edit', $course)->with('status', "Started {$course->title}. Add its lessons below, then publish it.");
    }

    public function edit(Course $course)
    {
        $course->load('workLessons', 'sections');
        $working = $course->workLessons->map(fn ($l) => $l->working());

        return view('training.build.course', [
            'course' => $course,
            'working' => $working,
            'pending' => $course->pendingChanges(),
            'latest' => $course->versions()->with('publisher')->first(),
            'questionCounts' => Question::whereIn('lesson_id', $working->pluck('id'))->selectRaw('lesson_id, COUNT(*) as n')->groupBy('lesson_id')->pluck('n', 'lesson_id'),
            'categories' => Course::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'assigned' => Assignment::current()->where('course_id', $course->id)->count(),
        ]);
    }

    public function update(Request $request, Course $course)
    {
        $data = $this->validatedCourse($request) + $request->validate(['status' => ['required', Rule::in(array_keys(Course::STATUSES))]]);
        $first = $data['status'] === 'published' && ! $course->isVersioned();
        if ($first && ($why = $this->cantPublish($course))) {
            return back()->withInput()->with('error', $why);
        }
        $course->update($data + ['updated_by' => $request->user()->id]);
        if ($first) {
            app(Versions::class)->publish($course, $request->user());
            audit_note("Published {$course->title} for the first time (version 1).");

            return back()->with('status', "Published {$course->title}. From now on, edits wait as unpublished changes until you publish them.");
        }

        return back()->with('status', "Saved {$course->title}.");
    }

    public function destroy(Course $course)
    {
        if ($course->assignments()->exists()) {
            return back()->with('error', "{$course->title} has been assigned, so its records stay. Set it to Archived instead.");
        }
        $paths = $course->allLessons()->pluck('file_path');
        $title = $course->title;
        $course->delete();
        $paths->each(fn ($p) => Versions::dropFile($p));
        audit_note("Deleted the course {$title}.");

        return redirect()->route('training.courses')->with('status', "Deleted {$title}.");
    }

    public function storeLesson(Request $request, Course $course, Training $training)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'type' => ['required', Rule::in(array_keys(Lesson::TYPES))],
            'section_id' => ['nullable', Rule::exists('training_sections', 'id')->where('course_id', $course->id)],
        ]);
        // Goes at the end of its section; renumber() puts it in place.
        $lesson = $course->lessons()->create($data + [
            'position' => 1000000,
            'pending_add' => $course->isVersioned(),
            'passing_score' => $data['type'] === 'quiz' ? Lesson::DEFAULT_PASSING : null,
        ]);
        $course->renumber();
        $course->update(['updated_by' => $request->user()->id]);
        $training->refreshCourse($course);
        audit_note("Added the {$lesson->typeLabel()} lesson \"{$lesson->title}\" to {$course->title}".($lesson->section ? " (section {$lesson->section->title})" : '').($lesson->pending_add ? ', not published yet' : '').'.');

        return redirect()->route('training.lessons.edit', $lesson)->with('status', 'Lesson added. Fill it in and Save.'.($lesson->pending_add ? ' Team Members see it once the course\'s changes are published.' : ''));
    }

    public function editLesson(Lesson $lesson)
    {
        // A published lesson with a pending edit opens the edit.
        if (! $lesson->draft_of && $lesson->draft) {
            return redirect()->route('training.lessons.edit', $lesson->draft);
        }
        $lesson->load('course.sections', 'course.workLessons', 'questions');

        return view('training.build.lesson', [
            'lesson' => $lesson,
            'course' => $lesson->course,
            'serverLimitMb' => self::serverLimitMb(),
        ]);
    }

    public function updateLesson(Request $request, Lesson $lesson)
    {
        $rules = [
            'title' => 'required|string|max:255',
            'body' => 'nullable|string|max:2000000',
            'min_minutes' => 'nullable|integer|min:0|max:600',
            'section_id' => ['nullable', Rule::exists('training_sections', 'id')->where('course_id', $lesson->course_id)],
        ];
        if ($lesson->type === 'link') {
            $rules['url'] = 'required|url:https,http|max:1000';
        }
        if ($lesson->hasFile()) {
            $rules['upload'] = [$lesson->file_path ? 'nullable' : 'required', 'file', 'max:1048576', 'extensions:'.implode(',', Lesson::FILE_TYPES[$lesson->type])];
        }
        if ($lesson->isQuiz()) {
            $rules += ['passing_score' => 'required|integer|min:1|max:100', 'max_attempts' => 'nullable|integer|min:1|max:99'];
        }
        $data = $request->validate($rules, [
            'upload.required' => 'Pick a file to upload.',
            'upload.extensions' => 'That type of file doesn\'t fit this lesson. It takes '.implode(', ', Lesson::FILE_TYPES[$lesson->type] ?? []).'.',
            'upload.uploaded' => 'That file is bigger than the server\'s upload limit ('.self::serverLimitMb().' MB).',
            'upload.max' => 'Files can be up to 1 GB.',
        ]);

        // Sections and order apply straight away (to the published lesson); content goes onto the pending edit.
        $base = $lesson->base();
        $sectionId = isset($data['section_id']) ? (int) $data['section_id'] : null;
        $moved = $request->has('section_id') && $sectionId !== $base->section_id;
        if ($moved) {
            $base->update(['section_id' => $sectionId, 'position' => 1000000]);
            $base->course->renumber();
        }
        $lesson = app(Versions::class)->draftFor($base);

        $update = [
            'title' => $data['title'],
            'body' => RichText::clean($data['body'] ?? null) ?: null,
            'min_seconds' => ! empty($data['min_minutes']) ? $data['min_minutes'] * 60 : null,
        ];
        if ($lesson->type === 'link') {
            $update['url'] = $data['url'];
        }
        if ($lesson->isQuiz()) {
            $update += [
                'passing_score' => $data['passing_score'],
                'max_attempts' => $data['max_attempts'] ?? null,
                'show_answers' => $request->boolean('show_answers'),
                'shuffle' => $request->boolean('shuffle'),
                'min_seconds' => null,
            ];
        }
        $replaced = null;
        if ($request->hasFile('upload')) {
            /** @var UploadedFile $file */
            $file = $request->file('upload');
            $replaced = $lesson->file_name;
            $old = $lesson->file_path;
            $update += [
                'file_path' => $file->storeAs('training/'.$lesson->course_id, $lesson->id.'-'.Str::random(12).'.'.strtolower($file->getClientOriginalExtension()), 'local'),
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'file_mime' => self::mimeFor($file),
                'file_size' => $file->getSize(),
            ];
        }
        $bodyChanged = ($lesson->body ?? null) !== ($update['body'] ?? null);
        $lesson->update($update);
        if (isset($old)) {
            Versions::dropFile($old);
        }
        $lesson->course->update(['updated_by' => $request->user()->id]);
        $notes = [];
        if (isset($update['file_name'])) {
            $notes[] = "file {$update['file_name']}".($replaced ? " (replacing {$replaced})" : '');
        }
        if ($bodyChanged) {
            $notes[] = 'page text edited ('.Str::limit(RichText::text($update['body']), 80).')';
        }
        $pending = $lesson->draft_of || $lesson->pending_add;
        audit_note("Saved the lesson \"{$lesson->title}\" in {$lesson->course->title}".($notes ? ': '.implode('; ', $notes) : '').($pending ? ' (unpublished change)' : '').'.');

        return redirect()->route('training.lessons.edit', $lesson)->with('status', "Saved {$lesson->title}.".($pending ? ' Team Members see it once the course\'s changes are published.' : ''));
    }

    public function destroyLesson(Request $request, Lesson $lesson, Training $training)
    {
        $course = $lesson->course;
        $title = $lesson->title;
        if ($lesson->draft_of) {
            $live = $lesson->live;
            app(Versions::class)->discard($lesson);
            audit_note("Threw away the unpublished changes to the lesson \"{$live->title}\" in {$course->title}.");

            return redirect()->route('training.lessons.edit', $live)->with('status', "Threw away the changes. {$live->title} is back to its published version.");
        }
        if ($course->isVersioned() && ! $lesson->pending_add) {
            $lesson->draft && app(Versions::class)->discard($lesson->draft);
            $lesson->update(['pending_remove' => true]);
            audit_note("Set the lesson \"{$title}\" to be removed from {$course->title} when its changes are published.");

            return redirect()->route('training.courses.edit', $course)->with('status', "{$title} will be removed when you publish the changes. Team Members still see it until then.");
        }
        $path = $lesson->file_path;
        $lesson->delete();
        Versions::dropFile($path);
        $course->renumber();
        $training->refreshCourse($course);
        audit_note("Removed the lesson \"{$title}\" from {$course->title}.");

        return redirect()->route('training.courses.edit', $course)->with('status', "Removed {$title}.");
    }

    /** Undo "remove" on a published lesson before the changes are published. */
    public function keepLesson(Lesson $lesson)
    {
        $lesson->update(['pending_remove' => false]);
        audit_note("Kept the lesson \"{$lesson->title}\" in {$lesson->course->title} (no longer set to be removed).");

        return back()->with('status', "Keeping {$lesson->title}.");
    }

    /** Publish the course's pending changes as its next version. */
    public function publishChanges(Request $request, Course $course)
    {
        $note = $request->validate(['note' => 'nullable|string|max:2000'])['note'] ?? null;
        if (! $course->isVersioned()) {
            return back()->with('error', 'Set the course to Published first.');
        }
        if (! $course->pendingChanges()) {
            return back()->with('error', 'There are no unpublished changes.');
        }
        if ($why = $this->cantPublish($course)) {
            return back()->with('error', $why);
        }
        $version = app(Versions::class)->publish($course, $request->user(), $note);
        $course->update(['updated_by' => $request->user()->id]);
        audit_note("Published version {$version->number} of {$course->title}: {$version->summary()}.".($note ? ' Note: '.rtrim($note, '. ').'.' : ''));

        return back()->with('status', "Published version {$version->number}. Team Members see it now.");
    }

    /** Version history, and who has looked at each version since. */
    public function versions(Course $course)
    {
        $views = \App\Training\Models\VersionView::with('user')->where('course_id', $course->id)->get()->groupBy('version');
        $finished = Assignment::current()->where('course_id', $course->id)->whereNotNull('completed_at')->with('user')->get();

        return view('training.build.versions', [
            'course' => $course,
            'versions' => $course->versions()->with('publisher')->get(),
            'views' => $views,
            'finished' => $finished,
        ]);
    }

    /** Why the working copy can't be published, or null. */
    private function cantPublish(Course $course): ?string
    {
        $working = $course->workLessons()->get()->reject(fn ($l) => $l->pending_remove)->map(fn ($l) => $l->working());
        if ($working->isEmpty()) {
            return 'Add at least one lesson before publishing.';
        }
        $empty = $working->filter(fn ($l) => $l->isQuiz() && ! $l->questions()->exists())->pluck('title');

        return $empty->isNotEmpty() ? 'Add questions to '.$empty->implode(', ').' before publishing.' : null;
    }

    public function moveLesson(Request $request, Lesson $lesson)
    {
        $up = $request->input('direction') === 'up';
        $lesson = $lesson->base();
        $this->move($lesson->course->workLessons()->where('section_id', $lesson->section_id)->get(), $lesson, $up);
        $lesson->course->renumber();
        audit_note("Moved the lesson \"{$lesson->title}\" ".($up ? 'up' : 'down')." in {$lesson->course->title}.");

        return back();
    }

    public function storeSection(Request $request, Course $course)
    {
        $data = $request->validate(['title' => 'required|string|max:255']);
        $section = $course->sections()->create($data + ['position' => (int) $course->sections()->max('position') + 1]);
        $course->update(['updated_by' => $request->user()->id]);
        audit_note("Added the section \"{$section->title}\" to {$course->title}.");
        if ($request->boolean('from_nav')) {
            return back()->with('status', "Added the section {$section->title}.");
        }

        return redirect()->to(route('training.courses.edit', $course).'#s'.$section->id)->with('status', "Added the section {$section->title}. Add lessons to it, or move lessons into it from their edit page.");
    }

    public function updateSection(Request $request, Section $section)
    {
        $data = $request->validate(['title' => 'required|string|max:255']);
        $section->update($data);
        $section->course->update(['updated_by' => $request->user()->id]);

        return redirect()->to(route('training.courses.edit', $section->course_id).'#s'.$section->id)->with('status', "Saved the section {$section->title}.");
    }

    public function moveSection(Request $request, Section $section)
    {
        $up = $request->input('direction') === 'up';
        $this->move($section->course->sections()->get(), $section, $up);
        $section->course->renumber();
        audit_note("Moved the section \"{$section->title}\" ".($up ? 'up' : 'down')." in {$section->course->title}.");
        if ($request->boolean('from_nav')) {
            return back();
        }

        return redirect()->to(route('training.courses.edit', $section->course_id).'#s'.$section->id);
    }

    /** Removes the section only. Its lessons stay in the course, outside any section. */
    public function destroySection(Request $request, Section $section)
    {
        $course = $section->course;
        $count = $section->lessons()->whereNull('draft_of')->count();
        $section->lessons()->update(['section_id' => null, 'position' => 0]);
        $section->delete();
        $course->renumber();
        $course->update(['updated_by' => $request->user()->id]);
        audit_note("Removed the section \"{$section->title}\" from {$course->title}".($count ? "; its {$count} ".Str::plural('lesson', $count).' stay in the course without a section' : '').'.');

        return redirect()->route('training.courses.edit', $course)->with('status', "Removed the section {$section->title}.");
    }

    public function previewLesson(Lesson $lesson)
    {
        // The working copy: what Team Members will see once the changes are published.
        $lesson = $lesson->base()->working();
        $lessons = $lesson->course->workLessons->reject(fn ($l) => $l->pending_remove)->map(fn ($l) => $l->working())->values();
        $index = $lessons->search(fn ($l) => $l->id === $lesson->id);

        return view('training.lesson', [
            'assignment' => null,
            'course' => $lesson->course,
            'lesson' => $lesson,
            'progress' => null,
            'previous' => $index > 0 ? $lessons[$index - 1] : null,
            'next' => $lessons[$index + 1] ?? null,
            'number' => $index + 1,
            'count' => $lessons->count(),
            'mine' => false,
            'preview' => true,
            'cantFinish' => null,
            'attempts' => collect(),
            'attemptInfo' => null,
            'cantAttempt' => null,
        ]);
    }

    public function storeQuestion(Request $request, Lesson $lesson)
    {
        abort_unless($lesson->isQuiz(), 404);
        $data = $this->validatedQuestion($request);
        $lesson = app(Versions::class)->draftFor($lesson);
        $question = $lesson->questions()->create($data + ['position' => (int) $lesson->questions()->max('position') + 1]);
        audit_note("Added question {$lesson->questions()->count()} to the quiz \"{$lesson->title}\" in {$lesson->course->title}: ".Str::limit($question->prompt, 120));

        return redirect()->to(route('training.lessons.edit', $lesson).'#new-question')->with('status', 'Question added.');
    }

    public function updateQuestion(Request $request, Question $question)
    {
        $data = $this->validatedQuestion($request);
        $question = app(Versions::class)->draftQuestion($question);
        $question->update($data);

        return redirect()->to(route('training.lessons.edit', $question->lesson_id).'#q'.$question->id)->with('status', 'Question saved.');
    }

    public function destroyQuestion(Question $question)
    {
        $question = app(Versions::class)->draftQuestion($question);
        $lesson = $question->lesson;
        $question->delete();
        audit_note("Removed a question from the quiz \"{$lesson->title}\" in {$lesson->course->title}: ".Str::limit($question->prompt, 120));

        return redirect()->route('training.lessons.edit', $lesson)->with('status', 'Question removed.');
    }

    public function moveQuestion(Request $request, Question $question)
    {
        $up = $request->input('direction') === 'up';
        $question = app(Versions::class)->draftQuestion($question);
        $this->move($question->lesson->questions()->get(), $question, $up);
        audit_note("Moved a question ".($up ? 'up' : 'down')." in the quiz \"{$question->lesson->title}\": ".Str::limit($question->prompt, 80));

        return redirect()->to(route('training.lessons.edit', $question->lesson_id).'#q'.$question->id);
    }

    /** A picture dropped into the course designer. Answers with its address for the editor. */
    public function storeImage(Request $request)
    {
        $request->validate(['image' => 'required|file|max:10240|mimetypes:image/jpeg,image/png,image/gif,image/webp'], [
            'image.mimetypes' => 'Pictures can be JPG, PNG, GIF or WebP.',
            'image.max' => 'Pictures can be up to 10 MB.',
        ]);
        $file = $request->file('image');
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$file->getMimeType()];
        $name = Str::random(40).'.'.$ext;
        $file->storeAs('training/images', $name, 'local');
        audit_note("Added a picture ({$file->getClientOriginalName()}) to a course page.");

        return response()->json(['url' => '/training/images/'.$name]);
    }

    /** Designer pictures. Their names can't be guessed, so they load without signing in (phone apps too). */
    public function image(string $name)
    {
        $path = 'training/images/'.$name;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function validatedCourse(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'category' => 'nullable|string|max:80',
            'due_days' => 'nullable|integer|min:1|max:3650',
        ]);
    }

    private function validatedQuestion(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Question::TYPES))],
            'prompt' => 'required|string|max:5000',
            'explanation' => 'nullable|string|max:5000',
            'choices' => 'nullable|array|max:10',
            'choices.*' => 'nullable|string|max:1000',
            'correct' => 'nullable|array',
            'correct.*' => 'integer',
            'true_false' => 'nullable|in:true,false',
        ]);
        if ($data['type'] === 'true_false') {
            if (! isset($data['true_false'])) {
                throw ValidationException::withMessages(['true_false' => 'Pick whether the answer is True or False.']);
            }
            $choices = [['text' => 'True', 'correct' => $data['true_false'] === 'true'], ['text' => 'False', 'correct' => $data['true_false'] === 'false']];
        } else {
            $correct = array_map('intval', $data['correct'] ?? []);
            $choices = [];
            foreach ($data['choices'] ?? [] as $i => $text) {
                if (trim((string) $text) !== '') {
                    $choices[] = ['text' => trim($text), 'correct' => in_array((int) $i, $correct, true)];
                }
            }
            $right = count(array_filter($choices, fn ($c) => $c['correct']));
            if (count($choices) < 2) {
                throw ValidationException::withMessages(['choices' => 'Give at least two answers to choose from.']);
            }
            if ($right === 0) {
                throw ValidationException::withMessages(['correct' => 'Tick the right answer.']);
            }
            if ($data['type'] === 'single' && $right > 1) {
                throw ValidationException::withMessages(['correct' => 'A one-answer question has only one right answer. Use "Select all that apply" for more.']);
            }
        }

        return ['type' => $data['type'], 'prompt' => trim($data['prompt']), 'explanation' => $data['explanation'] ?? null, 'choices' => $choices];
    }

    private function move($items, $item, bool $up): void
    {
        $items = $items->values();
        $i = $items->search(fn ($x) => $x->id === $item->id);
        $j = $up ? $i - 1 : $i + 1;
        if ($i === false || ! isset($items[$j])) {
            return;
        }
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        DB::transaction(function () use ($items) {
            foreach ($items as $pos => $x) {
                $x->newQuery()->whereKey($x->id)->update(['position' => $pos + 1]);
            }
        });
    }


    /** Browsers send odd types for Office files and MOV; the extension decides what's served. */
    private static function mimeFor(UploadedFile $file): string
    {
        return [
            'pdf' => 'application/pdf', 'ppt' => 'application/vnd.ms-powerpoint', 'pps' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
            'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
            'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain', 'csv' => 'text/csv', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'zip' => 'application/zip',
        ][strtolower($file->getClientOriginalExtension())] ?? 'application/octet-stream';
    }

    public static function serverLimitMb(): int
    {
        $toBytes = function (string $v): int {
            $n = (int) $v;

            return match (strtolower(substr(trim($v), -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
        };
        $limit = min($toBytes((string) ini_get('upload_max_filesize')), $toBytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX);

        return max(1, intdiv($limit, 1048576));
    }
}
