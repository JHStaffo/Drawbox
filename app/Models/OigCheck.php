<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One run of every active Team Member against the OIG exclusion list. */
class OigCheck extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['list_updated_at' => 'datetime'];
    }

    public function results(): HasMany
    {
        return $this->hasMany(OigCheckResult::class);
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    /** Recount after a manual check changes a result. */
    public function recount(): void
    {
        $results = $this->results()->get();
        $this->update([
            'excluded_count' => $results->filter(fn (OigCheckResult $r) => $r->isExcluded())->count(),
            'review_count' => $results->filter(fn (OigCheckResult $r) => $r->needsReview())->count(),
        ]);
    }
}
