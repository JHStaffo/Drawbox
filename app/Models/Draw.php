<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Draw extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'taxable' => 'boolean',
            'cash_bonus' => 'boolean',
            'pto_hours' => 'decimal:2',
            'drawn_at' => 'datetime',
            'awarded_at' => 'datetime',
        ];
    }

    public function label(): string
    {
        if ($this->cash_bonus) {
            return 'Cash bonus '.money($this->value_cents)." (instead of {$this->prize_name})";
        }

        return $this->brand ? "{$this->prize_name} ({$this->brand})" : $this->prize_name;
    }

    /** The winner can swap the prize for a taxable cash bonus until it's handed out. */
    public function canTakeCashBonus(): bool
    {
        return ! $this->cash_bonus
            && in_array($this->status, ['pending_brand', 'won'])
            && $this->value_cents > 0
            && $this->prize->cash_bonus_allowed;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(DrawCredit::class, 'draw_credit_id');
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(Prize::class);
    }

    public function awarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }
}
