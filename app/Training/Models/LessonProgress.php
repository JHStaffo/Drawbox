<?php

namespace App\Training\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How far one assignment is through one lesson: time on it, when it was finished, the best quiz score. */
class LessonProgress extends Model
{
    protected $table = 'training_lesson_progress';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_viewed_at' => 'datetime', 'last_ping_at' => 'datetime', 'completed_at' => 'datetime',
            'seconds_spent' => 'integer', 'best_score' => 'integer', 'extra_attempts' => 'integer',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
