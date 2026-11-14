<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Team Member's result in an OIG exclusion check. */
class OigCheckResult extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['matches' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(OigCheck::class, 'oig_check_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Excluded, counting a manual check over the automatic match. */
    public function isExcluded(): bool
    {
        return $this->review_outcome ? $this->review_outcome === 'excluded' : $this->status === 'excluded';
    }

    /** A name match with no date of birth to compare, that nobody has checked on the OIG website yet. */
    public function needsReview(): bool
    {
        return $this->review_outcome === null && $this->status === 'review';
    }

    public function label(): string
    {
        return match (true) {
            $this->isExcluded() => 'Excluded',
            $this->review_outcome === null && $this->status === 'review' => 'Needs manual check',
            default => 'Not excluded',
        };
    }

    public function pillClass(): string
    {
        return match ($this->label()) {
            'Excluded' => 'pill-red',
            'Needs manual check' => 'pill-amber',
            default => 'pill-green',
        };
    }
}
