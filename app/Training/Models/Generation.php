<?php

namespace App\Training\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A request to have Claude write a course. See App\Training\Generate\CourseGenerator. */
class Generation extends Model
{
    protected $table = 'training_generations';

    protected $guarded = [];

    public const STATUSES = ['queued' => 'Waiting', 'working' => 'Writing', 'done' => 'Ready to review', 'failed' => 'Stopped'];

    protected function casts(): array
    {
        return ['brief' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['queued', 'working'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
