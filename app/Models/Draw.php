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

    /** The Recent Winners wording, e.g. "a $200 SeaWorld gift card" or "a $50 cash bonus". */
    public function headline(): string
    {
        $what = match (true) {
            (bool) $this->cash_bonus => money($this->value_cents).' cash bonus',
            $this->prize_type === 'gift_card' && $this->brand => money($this->value_cents)." {$this->brand} gift card",
            default => $this->prize_name,
        };

        // "an" when the first sound is a vowel: "an Amazon", "an $80", "an $11".
        $an = preg_match('/^([aeiou]|\$(8|1[18](\D|$)))/i', $what);

        return ($an ? 'an ' : 'a ').$what;
    }

    /** What the winner picks: "brand" for gift cards, "choice" for other prizes. */
    public function choiceNoun(): string
    {
        return $this->prize_type === 'gift_card' ? 'brand' : 'choice';
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
        return $this->belongsTo(Prize::class)->withTrashed();
    }

    public function lockerAssignment(): BelongsTo
    {
        return $this->belongsTo(LockerAssignment::class);
    }

    public function awarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }
}
