<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LockerAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dispensed_at' => 'datetime',
            'due_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'forfeited_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    /** Waiting for the employee: not picked up, not forfeited. */
    public function scopeWaiting($query)
    {
        return $query->whereNull('picked_up_at')->whereNull('forfeited_at')->whereNull('cleared_at');
    }

    public function isWaiting(): bool
    {
        return ! $this->picked_up_at && ! $this->forfeited_at && ! $this->cleared_at;
    }

    /** Past the pickup date and still waiting for a supervisor to check the locker. */
    public function isOverdue(): bool
    {
        return $this->isWaiting() && $this->due_at->isPast();
    }

    /** Calendar days until the pickup date. The last day still counts as one. */
    public function daysLeft(): int
    {
        return max(1, (int) today()->diffInDays($this->due_at->copy()->startOfDay(), false));
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(Locker::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dispenser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensed_by');
    }

    public function draws(): HasMany
    {
        return $this->hasMany(Draw::class);
    }
}
