<?php

namespace App\Training\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A quiz question. Choices are [{text, correct}]; a "select all" question needs every right choice and no wrong one. */
class Question extends Model
{
    protected $table = 'training_questions';

    protected $guarded = [];

    public const TYPES = ['single' => 'One answer', 'multiple' => 'Select all that apply', 'true_false' => 'True or false'];

    protected static function booted(): void
    {
        // A quiz's expected time depends on its questions.
        $refresh = fn (Question $q) => $q->lesson?->save();
        static::saved($refresh);
        static::deleted($refresh);
    }

    protected function casts(): array
    {
        return ['choices' => 'array'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return list<int> indexes of the right choices */
    public function correctIndexes(): array
    {
        return array_keys(array_filter($this->choices ?? [], fn ($c) => ! empty($c['correct'])));
    }

    /** @param  list<int>  $chosen */
    public function isRight(array $chosen): bool
    {
        $chosen = array_values(array_unique(array_map('intval', $chosen)));
        sort($chosen);

        return $chosen !== [] && $chosen === $this->correctIndexes();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
