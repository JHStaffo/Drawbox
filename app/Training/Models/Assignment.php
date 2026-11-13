<?php

namespace App\Training\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A course given to one Team Member. Reassigning sets replaced_at here and
 * starts a new assignment, so every time they took it stays on record.
 */
class Assignment extends Model
{
    protected $table = 'training_assignments';

    protected $guarded = [];

    public const STATUSES = [
        'not_started' => 'Not started',
        'in_progress' => 'In progress',
        'overdue' => 'Overdue',
        'completed' => 'Completed',
        'replaced' => 'Reassigned',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date', 'started_at' => 'datetime', 'last_activity_at' => 'datetime', 'completed_at' => 'datetime',
            'replaced_at' => 'datetime', 'notified_at' => 'datetime', 'seconds_spent' => 'integer', 'percent' => 'integer', 'score' => 'integer',
        ];
    }

    /** Assignments still in effect (not replaced by a reassignment). */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('replaced_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function trainingProfile(): BelongsTo
    {
        return $this->belongsTo(TrainingProfile::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class)->orderBy('id');
    }

    public function status(): string
    {
        return match (true) {
            $this->replaced_at !== null => 'replaced',
            $this->completed_at !== null => 'completed',
            $this->due_on !== null && $this->due_on->lt(today()) => 'overdue',
            $this->started_at !== null => 'in_progress',
            default => 'not_started',
        };
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status()];
    }

    public function isOpen(): bool
    {
        return $this->replaced_at === null && $this->completed_at === null;
    }

    public function timeLabel(): string
    {
        return self::duration($this->seconds_spent);
    }

    /** 0 → "0 min", 4000 → "1 h 7 min". */
    public static function duration(?int $seconds): string
    {
        $minutes = intdiv((int) $seconds + 30, 60);
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : '');
    }
}
