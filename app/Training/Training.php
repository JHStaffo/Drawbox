<?php

namespace App\Training;

use App\Models\AuditLog;
use App\Models\User;
use App\Training\Models\Assignment;
use App\Training\Models\Attempt;
use App\Training\Models\Course;
use App\Training\Models\Lesson;
use App\Training\Models\LessonProgress;
use App\Training\Models\Question;
use App\Training\Models\TrainingProfile;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The rules of the Training module, shared by the web pages and the API:
 * assigning and reassigning, time on lessons, finishing lessons, quiz
 * attempts and grading, and course completion.
 */
class Training
{
    /** Longest stretch one time report can add, in seconds. Pages report every 15. */
    public const MAX_PING = 60;

    /**
     * Give a course to a Team Member. When they already have it open, nothing
     * happens unless $reassign is true; with $reassign, the current one (open
     * or finished) is kept as history and a fresh one starts.
     *
     * @return Assignment|null the new assignment, or null when it was skipped
     */
    public function assign(User $user, Course $course, ?User $by, array $options = []): ?Assignment
    {
        $reassign = $options['reassign'] ?? false;
        $current = Assignment::current()->where('user_id', $user->id)->where('course_id', $course->id)->latest('id')->first();
        if ($current && ! $reassign) {
            return null;
        }
        $due = $options['due_on'] ?? null;
        if (! $due && ($days = $options['due_days'] ?? $course->due_days)) {
            $due = today()->addDays($days);
        }

        return DB::transaction(function () use ($current, $user, $course, $by, $options, $due) {
            $current?->update(['replaced_at' => now(), 'replaced_by' => $by?->id]);

            return Assignment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'assigned_by' => $by?->id,
                'training_profile_id' => $options['training_profile_id'] ?? null,
                'reason' => $options['reason'] ?? null,
                'due_on' => $due instanceof CarbonInterface ? $due->toDateString() : $due,
            ]);
        });
    }

    /**
     * Give every published course in a training profile.
     *
     * @return array{assigned: int, skipped: int}
     */
    public function assignProfile(User $user, TrainingProfile $profile, ?User $by, array $options = []): array
    {
        $counts = ['assigned' => 0, 'skipped' => 0];
        foreach ($profile->courses->where('status', 'published') as $course) {
            $done = $this->assign($user, $course, $by, $options + [
                'training_profile_id' => $profile->id,
                'due_days' => $profile->due_days ?? $course->due_days,
            ]);
            $counts[$done ? 'assigned' : 'skipped']++;
        }

        return $counts;
    }

    /**
     * Training profiles that go automatically to this Team Member's permission
     * profile. Courses they already have (open or finished) aren't given again.
     */
    public function autoAssign(User $user, ?User $by = null): void
    {
        if (! $user->active || ! $user->permission_profile_id) {
            return;
        }
        // Looked up on its own so the caller's $user keeps no stale profile relation.
        $permissionProfile = \App\Models\PermissionProfile::find($user->permission_profile_id);
        $profiles = TrainingProfile::with('autoProfiles', 'courses')->whereHas('autoProfiles')->get()->filter(fn ($p) => $p->appliesTo($permissionProfile));
        foreach ($profiles as $profile) {
            $counts = $this->assignProfile($user, $profile, $by);
            if ($counts['assigned']) {
                AuditLog::record('training.auto-assign', "Gave {$user->name} the {$profile->name} training profile automatically ({$counts['assigned']} course(s)) because of their permission profile.", $by);
            }
        }
    }

    /** Opening a lesson counts as having started it. */
    public function open(Assignment $assignment, Lesson $lesson): LessonProgress
    {
        $progress = LessonProgress::firstOrCreate(['assignment_id' => $assignment->id, 'lesson_id' => $lesson->id]);
        if (! $progress->first_viewed_at) {
            $progress->update(['first_viewed_at' => now()]);
        }
        if (! $assignment->started_at && $assignment->isOpen()) {
            $assignment->update(['started_at' => now()]);
        }

        return $progress;
    }

    /**
     * Add time spent on a lesson. A report can't add more than MAX_PING seconds,
     * or more than has passed since the last report, so two open tabs or a
     * replayed request don't inflate the total.
     */
    public function addTime(Assignment $assignment, Lesson $lesson, int $seconds): int
    {
        if (! $assignment->isOpen()) {
            return 0;
        }
        $progress = $this->open($assignment, $lesson);
        $seconds = max(0, min($seconds, self::MAX_PING));
        $last = Assignment::query()->whereKey($assignment->id)->value('last_activity_at');
        if ($last) {
            $elapsed = (int) now()->diffInSeconds($last, true) + 2;
            $seconds = min($seconds, $elapsed);
        }
        if ($seconds <= 0) {
            return 0;
        }
        DB::transaction(function () use ($progress, $assignment, $seconds) {
            LessonProgress::whereKey($progress->id)->update(['seconds_spent' => DB::raw('seconds_spent + '.$seconds), 'last_ping_at' => now()]);
            Assignment::whereKey($assignment->id)->update(['seconds_spent' => DB::raw('seconds_spent + '.$seconds), 'last_activity_at' => now()]);
        });

        return $seconds;
    }

    /** Why "Mark as done" isn't allowed yet, or null when it is. */
    public function cantFinish(Assignment $assignment, Lesson $lesson): ?string
    {
        if (! $assignment->isOpen()) {
            return $assignment->replaced_at ? 'This course was reassigned. Open the new one from My Training.' : null;
        }
        if ($lesson->isQuiz()) {
            return 'A quiz is finished by passing it.';
        }
        $spent = (int) LessonProgress::where('assignment_id', $assignment->id)->where('lesson_id', $lesson->id)->value('seconds_spent');
        if ($lesson->min_seconds && $spent < $lesson->min_seconds) {
            return 'Spend at least '.Assignment::duration($lesson->min_seconds).' on this lesson first ('.Assignment::duration($spent).' so far).';
        }

        return null;
    }

    public function finishLesson(Assignment $assignment, Lesson $lesson): void
    {
        $progress = $this->open($assignment, $lesson);
        if (! $progress->completed_at) {
            $progress->update(['completed_at' => now()]);
        }
        $this->refresh($assignment);
    }

    /** Attempts used on a quiz, and how many are allowed (null = no limit). */
    public function attemptInfo(Assignment $assignment, Lesson $lesson): array
    {
        $used = Attempt::where('assignment_id', $assignment->id)->where('lesson_id', $lesson->id)->whereNotNull('submitted_at')->count();
        $extra = (int) LessonProgress::where('assignment_id', $assignment->id)->where('lesson_id', $lesson->id)->value('extra_attempts');
        $allowed = $lesson->max_attempts ? $lesson->max_attempts + $extra : null;

        return ['used' => $used, 'allowed' => $allowed, 'left' => $allowed === null ? null : max(0, $allowed - $used)];
    }

    /** Why a new attempt can't start, or null when it can. */
    public function cantAttempt(Assignment $assignment, Lesson $lesson): ?string
    {
        if (! $lesson->isQuiz()) {
            return 'That lesson isn\'t a quiz.';
        }
        if (! $assignment->isOpen()) {
            return $assignment->replaced_at ? 'This course was reassigned. Open the new one from My Training.' : 'This course is already complete.';
        }
        if ($lesson->questions()->count() === 0) {
            return 'This quiz has no questions yet.';
        }
        if (LessonProgress::where('assignment_id', $assignment->id)->where('lesson_id', $lesson->id)->whereNotNull('completed_at')->exists()) {
            return 'You already passed this quiz.';
        }
        if (($this->attemptInfo($assignment, $lesson)['left'] ?? 1) <= 0) {
            return 'You\'ve used every attempt on this quiz. Ask your trainer to allow another.';
        }

        return null;
    }

    /** Start a quiz attempt, or pick up one already in progress. */
    public function startAttempt(Assignment $assignment, Lesson $lesson): Attempt
    {
        $open = Attempt::where('assignment_id', $assignment->id)->where('lesson_id', $lesson->id)->whereNull('submitted_at')->latest('id')->first();
        if ($open) {
            return $open;
        }
        $this->open($assignment, $lesson);
        $ids = $lesson->questions()->pluck('id')->all();
        if ($lesson->shuffle) {
            shuffle($ids);
        }

        return Attempt::create([
            'assignment_id' => $assignment->id,
            'lesson_id' => $lesson->id,
            'user_id' => $assignment->user_id,
            'number' => Attempt::where('assignment_id', $assignment->id)->where('lesson_id', $lesson->id)->count() + 1,
            'started_at' => now(),
            'question_ids' => $ids,
        ]);
    }

    /** The questions of an attempt in the order they were shown (questions deleted since are left out). */
    public function attemptQuestions(Attempt $attempt): Collection
    {
        $questions = Question::whereIn('id', $attempt->question_ids)->get()->keyBy('id');

        return collect($attempt->question_ids)->map(fn ($id) => $questions[$id] ?? null)->filter()->values();
    }

    /**
     * Grade an attempt. $answers maps question id => chosen choice index(es).
     * Unanswered questions count as wrong.
     */
    public function submit(Attempt $attempt, array $answers): Attempt
    {
        $lesson = $attempt->lesson;
        $graded = [];
        $correct = 0;
        foreach ($this->attemptQuestions($attempt) as $q) {
            $chosen = array_values(array_map('intval', (array) ($answers[$q->id] ?? [])));
            $right = $q->isRight($chosen);
            $correct += $right ? 1 : 0;
            $graded[] = [
                'question_id' => $q->id,
                'prompt' => $q->prompt,
                'type' => $q->type,
                'choices' => array_map(fn ($c) => ['text' => $c['text'], 'correct' => ! empty($c['correct'])], $q->choices),
                'chosen' => $chosen,
                'right' => $right,
                'explanation' => $q->explanation,
            ];
        }
        $total = count($graded);
        $score = $total ? (int) floor($correct * 100 / $total) : 0;
        $passed = $total > 0 && $score >= $lesson->passing();

        DB::transaction(function () use ($attempt, $graded, $correct, $total, $score, $passed, $lesson) {
            $attempt->update([
                'submitted_at' => now(),
                'seconds' => (int) $attempt->started_at->diffInSeconds(now(), true),
                'answers' => $graded,
                'correct' => $correct,
                'total' => $total,
                'score' => $score,
                'passed' => $passed,
            ]);
            $assignment = $attempt->assignment;
            $progress = $this->open($assignment, $lesson);
            $progress->update([
                'best_score' => max((int) $progress->best_score, $score),
                'completed_at' => $progress->completed_at ?? ($passed ? now() : null),
            ]);
            $this->refresh($assignment);
        });

        return $attempt->fresh();
    }

    /** Recount completion % and the quiz grade; finish the course when every lesson is done. */
    public function refresh(Assignment $assignment): void
    {
        $assignment->refresh();
        if ($assignment->replaced_at) {
            return;
        }
        $lessons = Lesson::where('course_id', $assignment->course_id)->get(['id', 'type']);
        $progress = LessonProgress::where('assignment_id', $assignment->id)->whereIn('lesson_id', $lessons->pluck('id'))->get()->keyBy('lesson_id');
        $done = $progress->whereNotNull('completed_at')->count();
        $total = $lessons->count();
        $quizScores = $lessons->where('type', 'quiz')->map(fn ($l) => $progress[$l->id]?->best_score ?? null)->filter(fn ($s) => $s !== null);

        $data = [
            'percent' => $assignment->completed_at ? 100 : ($total ? (int) floor($done * 100 / $total) : 0),
            'score' => $quizScores->isEmpty() ? null : (int) round($quizScores->avg()),
        ];
        if (! $assignment->completed_at && $total > 0 && $done === $total) {
            $data['completed_at'] = now();
            $data['percent'] = 100;
        }
        $assignment->update($data);
    }

    /** Lessons of the course with this assignment's progress on each. */
    public function lessonsWithProgress(Assignment $assignment): Collection
    {
        $progress = LessonProgress::where('assignment_id', $assignment->id)->get()->keyBy('lesson_id');

        return $assignment->course->lessons->map(function (Lesson $lesson) use ($progress) {
            $lesson->setRelation('myProgress', $progress[$lesson->id] ?? null);

            return $lesson;
        });
    }

    /** After lessons are added or removed, recount every open assignment of the course. */
    public function refreshCourse(Course $course): void
    {
        Assignment::current()->whereNull('completed_at')->where('course_id', $course->id)->get()->each(fn ($a) => $this->refresh($a));
    }

    /** True when this person may see this assignment: their own, or they run training. */
    public static function canView(User $viewer, Assignment $assignment): bool
    {
        return $assignment->user_id === $viewer->id || $viewer->hasAnyPermission('training_assign', 'training_reports');
    }
}
