<?php

namespace App\Training\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** When a Team Member first opened, and last opened, one version of a course. */
class VersionView extends Model
{
    protected $table = 'training_version_views';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['version' => 'integer', 'views' => 'integer', 'first_viewed_at' => 'datetime', 'last_viewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
