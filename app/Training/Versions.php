<?php

namespace App\Training;

use App\Models\User;
use App\Training\Models\Assignment;
use App\Training\Models\Course;
use App\Training\Models\CourseVersion;
use App\Training\Models\Lesson;
use App\Training\Models\Question;
use App\Training\Models\VersionView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Course versions. A course that has been published is never changed under
 * Team Members' feet: an edit to a published lesson goes onto a copy of it
 * (a pending edit), new lessons wait unpublished, and removed lessons stay
 * until the changes are published together as the course's next version.
 * Order and sections take effect right away.
 */
class Versions
{
    /** Content fields a pending edit carries over to the published lesson. */
    private const CONTENT = ['title', 'body', 'file_path', 'file_name', 'file_mime', 'file_size', 'url', 'min_seconds', 'passing_score', 'max_attempts', 'show_answers', 'shuffle'];

    /** The row an edit to this lesson should change: itself, or its pending edit (made now if needed). */
    public function draftFor(Lesson $lesson): Lesson
    {
        $lesson = $lesson->base();
        if (! $lesson->course->isVersioned() || $lesson->pending_add) {
            return $lesson;
        }
        if ($lesson->draft) {
            return $lesson->draft;
        }

        return DB::transaction(function () use ($lesson) {
            $draft = $lesson->replicate(['est_seconds', 'est_basis', 'search_text']);
            $draft->fill(['draft_of' => $lesson->id, 'pending_add' => false, 'pending_remove' => false])->save();
            foreach ($lesson->questions as $q) {
                $draft->questions()->create($q->only(['position', 'type', 'prompt', 'choices', 'explanation']));
            }
            $lesson->setRelation('draft', $draft);

            return $draft;
        });
    }

    /** The same question on the row that should be edited (see draftFor). */
    public function draftQuestion(Question $question): Question
    {
        $draft = $this->draftFor($question->lesson);
        if ($draft->id === $question->lesson_id) {
            return $question;
        }
        $index = $question->lesson->questions()->pluck('id')->search($question->id);

        return $draft->questions()->get()->values()[$index] ?? abort(404);
    }

    /** Throw away a pending edit. Its own uploaded file goes too. */
    public function discard(Lesson $draft): void
    {
        $path = $draft->file_path;
        $draft->delete();
        self::dropFile($path);
    }

    /**
     * Publish every pending change as the course's next version (or its first
     * version, the first time it's published). Returns the version.
     */
    public function publish(Course $course, ?User $by, ?string $note = null): CourseVersion
    {
        return DB::transaction(function () use ($course, $by, $note) {
            $first = ! $course->isVersioned();
            $number = $course->version + 1;
            $changes = [];
            $dropFiles = [];
            foreach ($course->workLessons()->with('draft.questions')->get() as $lesson) {
                if ($first) {
                    $lesson->update(['changed_in' => $number]);
                    $changes[] = ['kind' => 'added', 'title' => $lesson->title, 'lesson_id' => $lesson->id];
                } elseif ($lesson->pending_remove) {
                    $changes[] = ['kind' => 'removed', 'title' => $lesson->title, 'lesson_id' => null];
                    $dropFiles[] = $lesson->file_path;
                    $dropFiles[] = $lesson->draft?->file_path;
                    $lesson->delete();
                } elseif ($lesson->pending_add) {
                    $lesson->update(['pending_add' => false, 'changed_in' => $number]);
                    $changes[] = ['kind' => 'added', 'title' => $lesson->title, 'lesson_id' => $lesson->id];
                } elseif ($draft = $lesson->draft) {
                    if ($lesson->file_path !== $draft->file_path) {
                        $dropFiles[] = $lesson->file_path;
                    }
                    $lesson->questions()->delete();
                    Question::where('lesson_id', $draft->id)->update(['lesson_id' => $lesson->id]);
                    $draft->delete();
                    $lesson->fill($draft->only(self::CONTENT) + ['changed_in' => $number])->save();
                    $changes[] = ['kind' => 'changed', 'title' => $lesson->title, 'lesson_id' => $lesson->id];
                }
            }
            $course->update(['version' => $number]);
            $course->renumber();
            foreach (array_filter($dropFiles) as $path) {
                self::dropFile($path);
            }
            app(Training::class)->refreshCourse($course);

            return $course->versions()->create([
                'number' => $number,
                'note' => $note ?: ($first ? 'First published.' : null),
                'changes' => $changes,
                'published_by' => $by?->id,
                'published_at' => now(),
            ]);
        });
    }

    /** Note that a Team Member opened their course (or a lesson of it): first and last time, per version. */
    public function recordView(Assignment $assignment, User $viewer): void
    {
        $course = $assignment->course;
        if ($assignment->user_id !== $viewer->id || ! $course->isVersioned()) {
            return;
        }
        $view = VersionView::firstOrNew(['user_id' => $viewer->id, 'course_id' => $course->id, 'version' => $course->version]);
        if ($view->exists) {
            $view->update(['last_viewed_at' => now(), 'views' => $view->views + 1]);
        } else {
            $view->fill(['first_viewed_at' => now(), 'last_viewed_at' => now(), 'views' => 1])->save();
        }
    }

    /**
     * The daily email to Team Members whose finished courses have a newer
     * version they haven't been told about. With training emails turned off in
     * Settings, those updates are marked as handled and never sent later.
     *
     * @return array{sent: int, skipped: int}
     */
    public function sendUpdateNotices(): array
    {
        $due = Assignment::current()->whereNotNull('completed_at')->whereNotNull('completed_version')
            ->whereHas('course', fn ($c) => $c->where('status', '!=', 'draft')->whereColumn('training_courses.version', '>', 'training_assignments.completed_version'))
            ->where(fn ($q) => $q->whereNull('notified_version')->orWhereColumn('notified_version', '<', DB::raw('(select version from training_courses where training_courses.id = training_assignments.course_id)')))
            ->with('user', 'course.versions')->get();
        $counts = ['sent' => 0, 'skipped' => 0];
        if ($due->isEmpty()) {
            return $counts;
        }
        if (! \App\Models\Setting::get('training_emails_enabled')) {
            foreach ($due as $a) {
                $a->update(['notified_version' => $a->course->version]);
            }
            $counts['skipped'] = $due->count();

            return $counts;
        }
        foreach ($due->groupBy('user_id') as $assignments) {
            $user = $assignments->first()->user;
            if (! $user?->active || ! $user->email) {
                continue;
            }
            try {
                $user->notify(new \App\Notifications\TrainingCoursesUpdated($assignments->values()));
            } catch (\Throwable $e) {
                report($e); // Tried again tomorrow.

                continue;
            }
            foreach ($assignments as $a) {
                $a->update(['notified_version' => $a->course->version]);
            }
            $counts['sent']++;
        }

        return $counts;
    }

    /** Delete an uploaded file once no lesson (published or pending) uses it. */
    public static function dropFile(?string $path): void
    {
        if ($path && ! Lesson::where('file_path', $path)->exists()) {
            Storage::disk('local')->delete($path);
        }
    }
}
