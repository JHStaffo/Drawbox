<?php

namespace App\Training\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Training\Models\Assignment;
use App\Training\Models\Course;
use App\Training\Models\Lesson;
use App\Training\Models\Question;
use App\Training\RichText;
use App\Training\Training;
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
        $courses = Course::withCount('lessons')
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
        return view('training.build.course', [
            'course' => $course->load('lessons'),
            'questionCounts' => Question::whereIn('lesson_id', $course->lessons->pluck('id'))->selectRaw('lesson_id, COUNT(*) as n')->groupBy('lesson_id')->pluck('n', 'lesson_id'),
            'categories' => Course::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'assigned' => Assignment::current()->where('course_id', $course->id)->count(),
        ]);
    }

    public function update(Request $request, Course $course)
    {
        $data = $this->validatedCourse($request) + $request->validate(['status' => ['required', Rule::in(array_keys(Course::STATUSES))]]);
        if ($data['status'] === 'published' && $course->lessons()->count() === 0) {
            return back()->withInput()->with('error', 'Add at least one lesson before publishing.');
        }
        $empty = $course->lessons()->where('type', 'quiz')->whereDoesntHave('questions')->pluck('title');
        if ($data['status'] === 'published' && $empty->isNotEmpty()) {
            return back()->withInput()->with('error', 'Add questions to '.$empty->implode(', ').' before publishing.');
        }
        $course->update($data + ['updated_by' => $request->user()->id]);

        return back()->with('status', "Saved {$course->title}.");
    }

    public function destroy(Course $course)
    {
        if ($course->assignments()->exists()) {
            return back()->with('error', "{$course->title} has been assigned, so its records stay. Set it to Archived instead.");
        }
        foreach ($course->lessons as $lesson) {
            $this->deleteFile($lesson->file_path);
        }
        $title = $course->title;
        $course->delete();
        audit_note("Deleted the course {$title}.");

        return redirect()->route('training.courses')->with('status', "Deleted {$title}.");
    }

    public function storeLesson(Request $request, Course $course, Training $training)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'type' => ['required', Rule::in(array_keys(Lesson::TYPES))],
        ]);
        $lesson = $course->lessons()->create($data + [
            'position' => (int) $course->lessons()->max('position') + 1,
            'passing_score' => $data['type'] === 'quiz' ? Lesson::DEFAULT_PASSING : null,
        ]);
        $course->update(['updated_by' => $request->user()->id]);
        $training->refreshCourse($course);
        audit_note("Added the {$lesson->typeLabel()} lesson \"{$lesson->title}\" to {$course->title}.");

        return redirect()->route('training.lessons.edit', $lesson)->with('status', 'Lesson added. Fill it in and Save.');
    }

    public function editLesson(Lesson $lesson)
    {
        return view('training.build.lesson', [
            'lesson' => $lesson->load('course', 'questions'),
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
            $this->deleteFile($old);
        }
        $bodyChanged = ($lesson->body ?? null) !== ($update['body'] ?? null);
        $lesson->update($update);
        $lesson->course->update(['updated_by' => $request->user()->id]);
        $notes = [];
        if (isset($update['file_name'])) {
            $notes[] = "file {$update['file_name']}".($replaced ? " (replacing {$replaced})" : '');
        }
        if ($bodyChanged) {
            $notes[] = 'page text edited ('.Str::limit(RichText::text($update['body']), 80).')';
        }
        audit_note("Saved the lesson \"{$lesson->title}\" in {$lesson->course->title}".($notes ? ': '.implode('; ', $notes) : '').'.');

        return back()->with('status', "Saved {$lesson->title}.");
    }

    public function destroyLesson(Request $request, Lesson $lesson, Training $training)
    {
        $course = $lesson->course;
        if ($course->isPublished() && $course->lessons()->count() === 1) {
            return back()->with('error', 'A published course needs at least one lesson. Add another first, or set the course to Draft.');
        }
        $this->deleteFile($lesson->file_path);
        $title = $lesson->title;
        $lesson->delete();
        $training->refreshCourse($course);
        audit_note("Removed the lesson \"{$title}\" from {$course->title}.");

        return redirect()->route('training.courses.edit', $course)->with('status', "Removed {$title}.");
    }

    public function moveLesson(Request $request, Lesson $lesson)
    {
        $up = $request->input('direction') === 'up';
        $this->move($lesson->course->lessons()->get(), $lesson, $up);
        audit_note("Moved the lesson \"{$lesson->title}\" ".($up ? 'up' : 'down')." in {$lesson->course->title}.");

        return back();
    }

    public function previewLesson(Lesson $lesson)
    {
        $lessons = $lesson->course->lessons;
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
        $question = $lesson->questions()->create($data + ['position' => (int) $lesson->questions()->max('position') + 1]);
        audit_note("Added question {$lesson->questions()->count()} to the quiz \"{$lesson->title}\" in {$lesson->course->title}: ".Str::limit($question->prompt, 120));

        return redirect()->to(route('training.lessons.edit', $lesson).'#new-question')->with('status', 'Question added.');
    }

    public function updateQuestion(Request $request, Question $question)
    {
        $question->update($this->validatedQuestion($request));

        return redirect()->to(route('training.lessons.edit', $question->lesson_id).'#q'.$question->id)->with('status', 'Question saved.');
    }

    public function destroyQuestion(Question $question)
    {
        $lesson = $question->lesson;
        $question->delete();
        audit_note("Removed a question from the quiz \"{$lesson->title}\" in {$lesson->course->title}: ".Str::limit($question->prompt, 120));

        return back()->with('status', 'Question removed.');
    }

    public function moveQuestion(Request $request, Question $question)
    {
        $up = $request->input('direction') === 'up';
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

    private function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
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
