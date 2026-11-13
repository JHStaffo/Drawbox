<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One protocol in the book. Its content lives in versions: Team Members see
 * the approved one, and builders work on a draft until the Medical Director
 * approves it.
 */
class Protocol extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['retired_at' => 'datetime'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProtocolVersion::class)->orderByDesc('version');
    }

    public function approved(): BelongsTo
    {
        return $this->belongsTo(ProtocolVersion::class, 'approved_version_id');
    }

    /** The newest version, whatever its state. */
    public function latest(): HasOne
    {
        return $this->hasOne(ProtocolVersion::class)->latestOfMany('version');
    }

    /** The version being worked on (draft, sent for approval, or sent back), if any. */
    public function openVersion(): ?ProtocolVersion
    {
        return $this->versions()->whereIn('status', ProtocolVersion::OPEN)->first();
    }

    /** Protocols Team Members can see: approved and not retired. */
    public function scopeInBook(Builder $query): Builder
    {
        return $query->whereNotNull('approved_version_id')->whereNull('retired_at');
    }

    public function isLive(): bool
    {
        return $this->approved_version_id && ! $this->retired_at;
    }
}
