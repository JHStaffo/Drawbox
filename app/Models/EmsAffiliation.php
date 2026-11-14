<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An agency besides ours that a Team Member has been listed with, and its review. */
class EmsAffiliation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['auto_flagged' => 'boolean', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'gone_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return array{0: string, 1: string} */
    public function pill(): array
    {
        return match ($this->status) {
            'acceptable' => ['Acceptable', 'pill-green'],
            'unacceptable' => [$this->auto_flagged ? 'Unacceptable (on the list)' : 'Unacceptable', 'pill-red'],
            default => ['To review', 'pill-amber'],
        };
    }
}
