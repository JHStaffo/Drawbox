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
        return ['expires_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    /** Open and not yet past its expiry, even if the expiry job hasn't run. */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('status', 'open')->where('expires_at', '>', now());
    }

    public function isAvailable(): bool
    {
        return $this->status === 'open' && $this->expires_at->isFuture();
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
