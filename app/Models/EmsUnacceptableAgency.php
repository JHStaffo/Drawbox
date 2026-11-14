<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An agency Team Members may not be affiliated with: flagged on sight, no review needed. */
class EmsUnacceptableAgency extends Model
{
    protected $guarded = [];

    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
