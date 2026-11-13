<?php

namespace App\Training\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One try at a quiz. Once submitted, answers holds each question as it was
 * asked (prompt, choices, which were right, what they picked), so later edits
 * to the quiz never change a grade on record.
 */
class Attempt extends Model
{
    protected $table = 'training_attempts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'question_ids' => 'array', 'answers' => 'array', 'started_at' => 'datetime', 'submitted_at' => 'datetime',
            'passed' => 'boolean', 'score' => 'integer', 'correct' => 'integer', 'total' => 'integer', 'seconds' => 'integer',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }
}
