<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DrawCredit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'available_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    /** Open and not yet past its expiry, even if the expiry job hasn't run. It may still be waiting to become drawable. */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open')->where('expires_at', '>', now());
    }

    /** Open, past its waiting period, and not yet expired: the team member can draw it now. */
    public function scopeAvailable(Builder $query): void
    {
        $query->open()->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()));
    }

    /** Credited, but still inside the waiting period before it can be drawn. */
    public function scopeWaiting(Builder $query): void
    {
        $query->open()->where('available_at', '>', now());
    }

    public function isWaiting(): bool
    {
        return $this->available_at?->isFuture() ?? false;
    }

    public function isAvailable(): bool
    {
        return $this->status === 'open' && $this->expires_at->isFuture() && ! $this->isWaiting();
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function draw(): HasOne
    {
        return $this->hasOne(Draw::class);
    }
}
