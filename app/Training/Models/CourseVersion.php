<?php

namespace App\Training\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One published version of a course: when, by whom, and what changed. */
class CourseVersion extends Model
{
    protected $table = 'training_course_versions';

    protected $guarded = [];

    public const KINDS = ['added' => 'Added', 'changed' => 'Changed', 'removed' => 'Removed'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'changes' => 'array', 'published_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** "Changed: Phones; Added: Night driving" */
    public function summary(): string
    {
        return collect($this->getAttribute('changes') ?? [])->groupBy('kind')
            ->sortBy(fn ($items, $kind) => array_search($kind, array_keys(self::KINDS)))
            ->map(fn ($items, $kind) => (self::KINDS[$kind] ?? $kind).': '.$items->pluck('title')->implode(', '))
            ->implode('; ');
    }
}
