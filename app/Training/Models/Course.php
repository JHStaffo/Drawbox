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
        return ['due_days' => 'integer'];
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('position')->orderBy('id');
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

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
