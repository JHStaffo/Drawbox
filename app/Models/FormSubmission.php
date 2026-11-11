<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One filled-out form. The answers keep their own copy of each question's
 * label, so later edits to the form never change what was submitted.
 */
class FormSubmission extends Model
{
    protected $guarded = [];

    /** Tries before the retry job gives up and leaves it for someone to resend by hand. */
    public const MAX_ATTEMPTS = 5;

    protected function casts(): array
    {
        return ['answers' => 'array', 'slack_sent_at' => 'datetime'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who the submission is about: the Team Member answer, or the sender. */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** The answer to a question, by its label at the time. */
    public function answer(string $label): ?string
    {
        return collect($this->answers)->firstWhere('label', $label)['value'] ?? null;
    }

    public function statusLabel(): string
    {
        return match ($this->slack_status) {
            'sent' => 'Posted to Slack',
            'failed' => 'Not posted yet',
            'off' => 'Saved only (no channel)',
            default => 'Posting…',
        };
    }

    public function statusClass(): string
    {
        return match ($this->slack_status) {
            'sent' => 'pill-green',
            'failed' => 'pill-red',
            default => '',
        };
    }
}
