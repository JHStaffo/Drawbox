<?php

namespace App\Training\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A named part of a course, such as Regulations or Procedures, that groups some of its lessons. */
class Section extends Model
{
    protected $table = 'training_sections';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('position')->orderBy('id');
    }
}
