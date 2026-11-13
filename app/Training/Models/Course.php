<?php

namespace App\Training\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A course: lessons in order, assigned to Team Members once it's published. */
class Course extends Model
{
    protected $table = 'training_courses';

    protected $guarded = [];

    public const STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'];

    protected function casts(): array
    {
        return ['due_days' => 'integer', 'version' => 'integer'];
    }

    /** The published lessons: what Team Members see. Unpublished additions and pending edits are left out. */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->whereNull('draft_of')->where('pending_add', false)->orderBy('position')->orderBy('id');
    }

    /** What the builder works on: published lessons (including ones set to be removed) and unpublished new ones, each with its pending edit. */
    public function workLessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->whereNull('draft_of')->with('draft')->orderBy('position')->orderBy('id');
    }

    /** Every lesson row, pending edits included. */
    public function allLessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CourseVersion::class)->orderByDesc('number');
    }

    /** Once a course has been published, edits wait as unpublished changes until they're published as a new version. */
    public function isVersioned(): bool
    {
        return $this->version >= 1;
    }

    /** What would change if the course were published now: [['kind' => added|changed|removed, 'title' => ..., 'lesson' => Lesson]]. */
    public function pendingChanges(): array
    {
        if (! $this->isVersioned()) {
            return [];
        }
        $changes = [];
        foreach ($this->workLessons()->get() as $l) {
            if ($l->pending_remove) {
                $changes[] = ['kind' => 'removed', 'title' => $l->title, 'lesson' => $l];
            } elseif ($l->pending_add) {
                $changes[] = ['kind' => 'added', 'title' => $l->title, 'lesson' => $l];
            } elseif ($l->draft) {
                $changes[] = ['kind' => 'changed', 'title' => $l->draft->title, 'lesson' => $l->draft];
            }
        }

        return $changes;
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Lessons grouped for display: [['section' => ?Section, 'lessons' => Collection], ...].
     * Lessons outside any section come first, then each section in order (empty ones too, when asked).
     */
    public function lessonGroups($lessons = null, bool $withEmpty = false): array
    {
        $lessons = collect($lessons ?? $this->lessons);
        $groups = [];
        $loose = $lessons->whereNull('section_id')->values();
        if ($loose->isNotEmpty()) {
            $groups[] = ['section' => null, 'lessons' => $loose];
        }
        foreach ($this->sections as $section) {
            $in = $lessons->where('section_id', $section->id)->values();
            if ($in->isNotEmpty() || $withEmpty) {
                $groups[] = ['section' => $section, 'lessons' => $in];
            }
        }

        return $groups;
    }

    /** Numbers sections 1..n, and lessons 1..n in the order they're shown (loose lessons, then by section). */
    public function renumber(): void
    {
        $sections = $this->sections()->get()->values();
        foreach ($sections as $i => $s) {
            if ($s->position !== $i + 1) {
                $s->newQuery()->whereKey($s->id)->update(['position' => $i + 1]);
            }
        }
        $order = $sections->pluck('id')->flip();
        $lessons = $this->workLessons()->get()
            ->sortBy(fn ($l) => [$l->section_id === null ? -1 : ($order[$l->section_id] ?? 999), $l->position, $l->id])->values();
        foreach ($lessons as $i => $l) {
            if ($l->position !== $i + 1) {
                $l->newQuery()->whereKey($l->id)->update(['position' => $i + 1]);
            }
        }
        // A pending edit sits with the lesson it edits.
        foreach ($lessons as $l) {
            Lesson::where('draft_of', $l->id)->update(['position' => $l->newQuery()->whereKey($l->id)->value('position'), 'section_id' => $l->section_id]);
        }
        $this->unsetRelation('lessons')->unsetRelation('sections')->unsetRelation('workLessons');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(TrainingProfile::class, 'training_profile_courses', 'course_id', 'training_profile_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Expected time for the whole course: the sum of its lessons' (see App\Training\Estimate). */
    public function estimatedSeconds(): int
    {
        return $this->relationLoaded('lessons') ? (int) $this->lessons->sum('est_seconds') : (int) $this->lessons()->sum('est_seconds');
    }

    public function estimateLabel(): string
    {
        return \App\Training\Estimate::label($this->estimatedSeconds());
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
