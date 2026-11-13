<?php

namespace App\Wellness\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Team Member who has joined the wellness program and agreed to its rules. */
class Participant extends Model
{
    protected $table = 'wellness_participants';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime', 'consent_at' => 'datetime', 'withdrawn_at' => 'datetime',
            'manual_device_until' => 'date', 'sync_notice_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** True when an approved exception lets them type steps, sleep and workout in by hand on this day. */
    public function hasDeviceException(string $day): bool
    {
        return $this->manual_device_until && $this->manual_device_until->toDateString() >= $day;
    }
}
