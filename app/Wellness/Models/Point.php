<?php

namespace App\Wellness\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of the points ledger. */
class Point extends Model
{
    protected $table = 'wellness_points';

    protected $guarded = [];

    protected function casts(): array
    {
        // day stays a plain Y-m-d string so it matches the same way on MySQL and SQLite.
        return ['points' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }
}
