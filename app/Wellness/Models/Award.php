<?php

namespace App\Wellness\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reward earned: for now the weekly $10 gift card for 56 daily-habit points. */
class Award extends Model
{
    protected $table = 'wellness_awards';

    protected $guarded = [];

    public const STATUSES = ['pending' => 'To send', 'sent' => 'Sent', 'void' => 'Voided'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'test_period' => 'boolean',
            'brand_chosen_at' => 'datetime', 'sent_at' => 'datetime', 'points' => 'integer', 'value_cents' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function label(): string
    {
        return money($this->value_cents).' gift card, week of '.$this->period_start->format('M j').' to '.$this->period_end->format('M j, Y');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
