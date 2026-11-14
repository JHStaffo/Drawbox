<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One search of every active Team Member on the Virginia EMS provider search. */
class EmsCheck extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function results(): HasMany
    {
        return $this->hasMany(EmsCheckResult::class);
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    /** Things someone should look at. */
    public function attentionCount(): int
    {
        return $this->not_found_count + $this->missing_ours_count + $this->review_count + $this->unacceptable_count + $this->problem_count;
    }

    /** Recount from the results, after a check or a review. */
    public function recount(): void
    {
        $results = $this->results()->get();
        $this->update([
            'found_count' => $results->where('status', 'found')->count(),
            'not_found_count' => $results->filter(fn (EmsCheckResult $r) => $r->status === 'not_found' && ! $r->accepted_exclusion)->count(),
            'excluded_count' => $results->filter(fn (EmsCheckResult $r) => $r->status === 'not_found' && $r->accepted_exclusion)->count(),
            'missing_ours_count' => $results->filter(fn (EmsCheckResult $r) => $r->status === 'found' && ! $r->has_ours)->count(),
            'review_count' => $results->sum(fn (EmsCheckResult $r) => count($r->review_agencies ?? [])),
            'unacceptable_count' => $results->sum(fn (EmsCheckResult $r) => count($r->unacceptable_agencies ?? [])),
            'problem_count' => $results->whereIn('status', ['multiple', 'failed'])->count(),
        ]);
    }
}
