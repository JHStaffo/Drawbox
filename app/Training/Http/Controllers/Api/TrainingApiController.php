<?php

namespace App\Training\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Training\Models\Assignment;
use App\Training\Models\Attempt;
use App\Training\Models\Lesson;
use App\Training\Training;
use Illuminate\Http\Request;

/**
 * Training for phone apps: the same rules as the website (see App\Training\Training).
 * Files come as two-hour signed links; quiz questions never include the answers
 * until an attempt is submitted.
 */
class TrainingApiController extends Controller
{
    public function assignments(Request $request)
    {
        $all = Assignment::with('course')->where('user_id', $request->user()->id)->whereNull('replaced_at')->latest('id')->get();

        return response()->json(['assignments' => $all->map(fn ($a) => $this->assignment($a))->values()]);
    }

    public function show(Request $request, Assignment $assignment, Training $training)
    {
        $this->own($request, $assignment);

        return response()->json([
            'assignment' => $this->assignment($assignment),
            'sections' => $assignment->course->sections->map(fn ($s) => ['id' => $s->id, 'title' => $s->title])->values(),
            'lessons' => $training->lessonsWithProgress($assignment)->map(fn (Lesson $l) => $this->lesson($l, $assignment, $training))->values(),
        ]);
    }

    public function time(Request $request, Assignment $assignment, Lesson $lesson, Training $training)
    {
        $this->own($request, $assignment, $lesson);
        $seconds = (int) $request->validate(['seconds' => 'required|integer|min:0|max:600'])['seconds'];

        return response()->json(['added' => $training->addTime($assignment, $lesson, $seconds)]);
    }

    public function done(Request $request, Assignment $assignment, Lesson $lesson, Training $training)
    {
        $this->own($request, $assignment, $lesson);
        if ($why = $training->cantFinish($assignment, $lesson)) {
            return response()->json(['message' => $why], 422);
        }
        $training->finishLesson($assignment, $lesson);
        $assignment->refresh();
        audit_note("Finished lesson \"{$lesson->title}\" in {$assignment->course->title} ({$assignment->percent}% of the course done).");

        return response()->json(['assignment' => $this->assignment($assignment)]);
    }

    public function startAttempt(Request $request, Assignment $assignment, Lesson $lesson, Training $training)
    {
        $this->own($request, $assignment, $lesson);
        if ($why = $training->cantAttempt($assignment, $lesson)) {
            return response()->json(['message' => $why], 422);
        }
        $attempt = $training->startAttempt($assignment, $lesson);
        audit_note("Started attempt {$attempt->number} at the quiz \"{$lesson->title}\" in {$assignment->course->title}.");

        return response()->json([
            'attempt' => ['id' => $attempt->id, 'number' => $attempt->number, 'started_at' => $attempt->started_at->toIso8601String()],
            'questions' => $training->attemptQuestions($attempt)->map(fn ($q) => [
                'id' => $q->id, 'type' => $q->type, 'prompt' => $q->prompt,
                'choices' => collect($q->choices)->map(fn ($c, $i) => ['index' => $i, 'text' => $c['text']])->values(),
            ]),
        ], 201);
    }

    public function submit(Request $request, Attempt $attempt, Training $training)
    {
        abort_unless($attempt->user_id === $request->user()->id, 403);
        if ($attempt->isSubmitted()) {
            return response()->json(['message' => 'That attempt was already submitted.'], 422);
        }
        if (! $attempt->assignment->isOpen()) {
            return response()->json(['message' => 'This course was reassigned.'], 422);
        }
        $answers = $request->validate(['answers' => 'nullable|array'])['answers'] ?? [];
        $attempt = $training->submit($attempt, $answers);
        $lesson = $attempt->lesson;
        audit_note("Quiz \"{$lesson->title}\", attempt {$attempt->number}: {$attempt->score}% ({$attempt->correct} of {$attempt->total} right), ".($attempt->passed ? 'passed.' : 'not passed.'));

        return response()->json([
            'score' => $attempt->score, 'correct' => $attempt->correct, 'total' => $attempt->total, 'passed' => $attempt->passed,
            'passing_score' => $lesson->passing(),
            'results' => $lesson->show_answers ? $attempt->answers : collect($attempt->answers)->map(fn ($a) => ['question_id' => $a['question_id'], 'right' => $a['right']]),
            'assignment' => $this->assignment($attempt->assignment->fresh()),
        ]);
    }

    private function assignment(Assignment $a): array
    {
        return [
            'id' => $a->id,
            'course' => ['id' => $a->course->id, 'title' => $a->course->title, 'description' => $a->course->description, 'category' => $a->course->category, 'estimated_seconds' => $a->course->estimatedSeconds()],
            'status' => $a->status(),
            'percent' => $a->percent,
            'seconds_spent' => $a->seconds_spent,
            'score' => $a->score,
            'due_on' => $a->due_on?->toDateString(),
            'assigned_at' => $a->created_at->toIso8601String(),
            'started_at' => $a->started_at?->toIso8601String(),
            'completed_at' => $a->completed_at?->toIso8601String(),
        ];
    }

    private function lesson(Lesson $l, Assignment $assignment, Training $training): array
    {
        $p = $l->myProgress;

        return [
            'id' => $l->id,
            'title' => $l->title,
            'section' => $l->section_id ? $l->section?->title : null,
            'type' => $l->type,
            'html' => $l->body,
            'url' => $l->url,
            'embed_url' => $l->type === 'link' ? $l->embedUrl() : null,
            'file' => $l->file_path ? ['name' => $l->file_name, 'mime' => $l->file_mime, 'size' => $l->file_size, 'url' => $l->signedFileUrl()] : null,
            'min_seconds' => $l->min_seconds,
            'estimated_seconds' => $l->est_seconds,
            'seconds_spent' => $p?->seconds_spent ?? 0,
            'completed_at' => $p?->completed_at?->toIso8601String(),
            'quiz' => $l->isQuiz() ? [
                'passing_score' => $l->passing(),
                'best_score' => $p?->best_score,
                'questions' => $l->questions()->count(),
            ] + $training->attemptInfo($assignment, $l) : null,
        ];
    }

    private function own(Request $request, Assignment $assignment, ?Lesson $lesson = null): void
    {
        abort_unless($assignment->user_id === $request->user()->id, 403);
        abort_if($lesson && $lesson->course_id !== $assignment->course_id, 404);
    }
}
