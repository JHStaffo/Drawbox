<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Team Member's result in an EMS affiliation check. */
class EmsCheckResult extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'has_ours' => 'boolean',
            'accepted_exclusion' => 'boolean',
            'exclusion_found' => 'boolean',
            'agencies' => 'array',
            'new_agencies' => 'array',
            'unacceptable_agencies' => 'array',
            'review_agencies' => 'array',
            'providers' => 'array',
        ];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(EmsCheck::class, 'ems_check_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Lower sorts first: the ones that need attention. */
    public function rank(): int
    {
        return match (true) {
            (bool) $this->unacceptable_agencies => 0,
            $this->status === 'found' && ! $this->has_ours => 1,
            $this->exclusion_found => 2,
            (bool) $this->review_agencies => 3,
            in_array($this->status, ['multiple', 'failed'], true) => 4,
            $this->status === 'not_found' && ! $this->accepted_exclusion => 5,
            default => 6,
        };
    }

    /** @return array{0: string, 1: string} label and pill class */
    public function pill(): array
    {
        return match ($this->status) {
            'failed' => ['Search failed', 'pill-amber'],
            'multiple' => ['More than one match', 'pill-amber'],
            'not_found' => $this->accepted_exclusion ? ['Not found (accepted exclusion)', 'pill-teal'] : ['Not found', 'pill-amber'],
            default => $this->has_ours ? ['Listed with us', 'pill-green'] : ['Not listed with us', 'pill-red'],
        };
    }
}
