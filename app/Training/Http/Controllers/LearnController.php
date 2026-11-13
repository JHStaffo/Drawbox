<?php

namespace App\Training\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Training\Models\Assignment;
use App\Training\Models\Attempt;
use App\Training\Models\Lesson;
use App\Training\Training;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** My Training: the courses a Team Member was given, their lessons and quizzes. */
class LearnController extends Controller
{
    public function index(Request $request)
    {
        $all = Assignment::with('course.lessons')->withCount('attempts')->where('user_id', $request->user()->id)->latest('id')->get();
        // Each finished course once (the latest time they finished it), kept as reference material.
        $done = $all->whereNotNull('completed_at')->unique('course_id')->sortBy(fn ($a) => mb_strtolower($a->course->title))->values();
        $q = trim((string) $request->query('q'));

        return view('training.index', [
            'open' => $all->filter(fn ($a) => $a->isOpen())->sortBy(fn ($a) => [$a->due_on?->timestamp ?? PHP_INT_MAX, $a->id])->values(),
            'done' => $done,
            'q' => $q,
            'results' => $q !== '' ? self::search($q, $done) : null,
        ]);
    }

    /**
     * Lessons of their finished courses that contain every word searched for
     * (in the lesson's title, its page text or the course title). Quiz questions aren't searched.
     *
     * @return \Illuminate\Support\Collection<int, array{lesson: Lesson, assignment: Assignment, snippet: string}>
     */
    public static function search(string $q, $done)
    {
        $terms = collect(preg_split('/\s+/', mb_strtolower($q)))->filter(fn ($t) => mb_strlen($t) >= 2)->unique()->take(8)->values();
        if ($terms->isEmpty() || $done->isEmpty()) {
            return collect();
        }
        $byCourse = $done->keyBy('course_id');
        $query = Lesson::with('course')->whereIn('course_id', $byCourse->keys())->where('type', '!=', 'quiz');
        foreach ($terms as $term) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($w) => $w->where('search_text', 'like', $like)->orWhereHas('course', fn ($c) => $c->where('title', 'like', $like)));
        }

        return $query->orderBy('course_id')->orderBy('position')->limit(50)->get()->map(function (Lesson $lesson) use ($byCourse, $terms) {
            $text = (string) $lesson->search_text;
            $at = $terms->map(fn ($t) => mb_stripos($text, $t))->filter(fn ($p) => $p !== false)->min() ?? 0;
            $start = max(0, $at - 80);
            $snippet = ($start > 0 ? '…' : '').mb_substr($text, $start, 240).(mb_strlen($text) > $start + 240 ? '…' : '');

            return ['lesson' => $lesson, 'assignment' => $byCourse[$lesson->course_id], 'snippet' => $snippet];
        });
    }

    public function course(Request $request, Assignment $assignment, Training $training)
    {
        $this->authorizeView($request, $assignment);

        return view('training.course', [
            'assignment' => $assignment,
            'course' => $assignment->course,
            'lessons' => $training->lessonsWithProgress($assignment),
            'mine' => $assignment->user_id === $request->user()->id,
        ]);
    }

    public function lesson(Request $request, Assignment $assignment, Lesson $lesson, Training $training)
    {
        $this->authorizeView($request, $assignment, $lesson);
        $mine = $assignment->user_id === $request->user()->id;
        $progress = $mine && $assignment->isOpen() ? $training->open($assignment, $lesson) : $assignment->progress()->where('lesson_id', $lesson->id)->first();
        $lessons = $assignment->course->lessons;
        $index = $lessons->search(fn ($l) => $l->id === $lesson->id);

        return view('training.lesson', [
            'assignment' => $assignment,
            'course' => $assignment->course,
            'lesson' => $lesson,
            'progress' => $progress,
            'previous' => $index > 0 ? $lessons[$index - 1] : null,
            'next' => $lessons[$index + 1] ?? null,
            'number' => $index + 1,
            'count' => $lessons->count(),
            'mine' => $mine,
            'preview' => false,
            'cantFinish' => $training->cantFinish($assignment, $lesson),
            'attempts' => $lesson->isQuiz() ? Attempt::where('assignment_id', $assignment->id)->where('lesson_id', $lesson->id)->orderBy('id')->get() : collect(),
            'attemptInfo' => $lesson->isQuiz() ? $training->attemptInfo($assignment, $lesson) : null,
            'cantAttempt' => $lesson->isQuiz() ? $training->cantAttempt($assignment, $lesson) : null,
        ]);
    }

    /** Time on a lesson, sent by the page every 15 seconds while it's open and in use. Not in the audit log. */
    public function time(Request $request, Assignment $assignment, Lesson $lesson, Training $training)
    {
        $this->authorizeOwn($request, $assignment, $lesson);
        $seconds = (int) $request->validate(['seconds' => 'required|integer|min:0|max:600'])['seconds'];
        $added = $training->addTime($assignment, $lesson, $seconds);

        return response()->json(['added' => $added]);
    }

    public function done(Request $request, Assignment $assignment, Lesson $lesson, Training $training)
    {
        $this->authorizeOwn($request, $assignment, $lesson);
        if ($why = $training->cantFinish($assignment, $lesson)) {
            return back()->with('error', $why);
        }
        $training->finishLesson($assignment, $lesson);
        $assignment->refresh();
        audit_note("Finished lesson \"{$lesson->title}\" in {$assignment->course->title} ({$assignment->percent}% of the course done".($assignment->completed_at ? ', course complete' : '').').');

        if ($assignment->completed_at) {
            return redirect()->route('training.course', $assignment)->with('status', "You finished {$assignment->course->title}.");
        }
        $next = $assignment->course->lessons->first(fn ($l) => $l->position > $lesson->position || ($l->position === $lesson->position && $l->id > $lesson->id));

        return $next
            ? redirect()->route('training.lesson', [$assignment, $next])->with('status', "Done: {$lesson->title}.")
            : redirect()->route('training.course', $assignment)->with('status', "Done: {$lesson->title}.");
    }

    public function startAttempt(Request $request, Assignment $assignment, Lesson $lesson, Training $training)
    {
        $this->authorizeOwn($request, $assignment, $lesson);
        if ($why = $training->cantAttempt($assignment, $lesson)) {
            return back()->with('error', $why);
        }
        $attempt = $training->startAttempt($assignment, $lesson);
        audit_note("Started attempt {$attempt->number} at the quiz \"{$lesson->title}\" in {$assignment->course->title}.");

        return redirect()->route('training.attempt', $attempt);
    }

    public function attempt(Request $request, Attempt $attempt, Training $training)
    {
        $assignment = $attempt->assignment;
        $this->authorizeView($request, $assignment);
        $mine = $assignment->user_id === $request->user()->id;
        abort_if(! $attempt->isSubmitted() && ! $mine, 404);

        return view('training.attempt', [
            'attempt' => $attempt,
            'assignment' => $assignment,
            'lesson' => $attempt->lesson,
            'questions' => $attempt->isSubmitted() ? collect() : $training->attemptQuestions($attempt),
            'mine' => $mine,
            // Team Members see the right answers only when the quiz allows it; trainers always do.
            'reveal' => ! $mine || $attempt->lesson->show_answers,
            'cantAttempt' => $mine ? $training->cantAttempt($assignment, $attempt->lesson) : 'x',
        ]);
    }

    public function submit(Request $request, Attempt $attempt, Training $training)
    {
        $assignment = $attempt->assignment;
        abort_unless($assignment->user_id === $request->user()->id, 403);
        if ($attempt->isSubmitted()) {
            return redirect()->route('training.attempt', $attempt);
        }
        if (! $assignment->isOpen()) {
            return redirect()->route('training')->with('error', 'This course was reassigned. Open the new one from My Training.');
        }
        $answers = $request->validate(['answers' => 'nullable|array', 'answers.*' => 'nullable'])['answers'] ?? [];
        $attempt = $training->submit($attempt, $answers);
        $lesson = $attempt->lesson;
        $assignment->refresh();
        audit_note("Quiz \"{$lesson->title}\" in {$assignment->course->title}, attempt {$attempt->number}: {$attempt->score}% ({$attempt->correct} of {$attempt->total} right), ".($attempt->passed ? 'passed' : 'not passed (needs '.$lesson->passing().'%)').($assignment->completed_at ? '. Course complete.' : '.'));

        return redirect()->route('training.attempt', $attempt)
            ->with($attempt->passed ? 'status' : 'error', $attempt->passed ? "You passed with {$attempt->score}%." : "You scored {$attempt->score}%. You need {$lesson->passing()}% to pass.");
    }

    /** A lesson's file, for someone signed in who has the course (or builds courses). */
    public function file(Request $request, Lesson $lesson)
    {
        $user = $request->user();
        $allowed = $user->hasAnyPermission('training_build', 'training_assign', 'training_reports')
            || Assignment::where('user_id', $user->id)->where('course_id', $lesson->course_id)->exists();
        abort_unless($allowed, 403);

        return self::sendFile($lesson, $request->boolean('download'));
    }

    /** The same file through a two-hour signed link (the PowerPoint viewer and phone apps). */
    public function publicFile(Lesson $lesson)
    {
        return self::sendFile($lesson, false);
    }

    public static function sendFile(Lesson $lesson, bool $download)
    {
        abort_unless($lesson->file_path && Storage::disk('local')->exists($lesson->file_path), 404);
        $path = Storage::disk('local')->path($lesson->file_path);
        $headers = ['Content-Type' => $lesson->file_mime ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'];

        return $download
            ? response()->download($path, $lesson->file_name, $headers)
            : response()->file($path, $headers + ['Content-Disposition' => 'inline; filename="'.addcslashes($lesson->file_name ?: 'file', '"\\').'"']);
    }

    private function authorizeView(Request $request, Assignment $assignment, ?Lesson $lesson = null): void
    {
        abort_unless(Training::canView($request->user(), $assignment), 403);
        abort_if($lesson && $lesson->course_id !== $assignment->course_id, 404);
    }

    private function authorizeOwn(Request $request, Assignment $assignment, Lesson $lesson): void
    {
        abort_unless($assignment->user_id === $request->user()->id, 403);
        abort_if($lesson->course_id !== $assignment->course_id, 404);
    }
}
