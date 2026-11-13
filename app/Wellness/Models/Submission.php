<?php

namespace App\Wellness\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An activity that needs review before it scores: a visit with its signed
 * attendance note, an InBody scan, or a weigh-in.
 */
class Submission extends Model
{
    protected $table = 'wellness_submissions';

    protected $guarded = [];

    public const STATUSES = ['pending' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Not approved', 'withdrawn' => 'Withdrawn'];

    protected function casts(): array
    {
        // day stays a plain Y-m-d string, like readings and points.
        return ['measures' => 'encrypted:array', 'reviewed_at' => 'datetime', 'points' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resubmission_of');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function dayLabel(): string
    {
        return \Carbon\Carbon::parse($this->day)->format('M j, Y');
    }
}
