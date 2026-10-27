<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prize extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public const TYPES = [
        'item' => 'Item',
        'pto' => 'PTO',
        'gift_card' => 'Gift card',
    ];

    protected static function booted(): void
    {
        // A new prize starts with its monthly amount of draws left, or what's on hand without one.
        static::creating(function (Prize $prize) {
            $prize->available ??= $prize->monthly_quantity ?: ($prize->quantity ?? 0);
        });
    }

    protected function casts(): array
    {
        return ['available' => 'integer', 'quantity' => 'integer', 'monthly_quantity' => 'integer', 'diminishing' => 'boolean', 'taxable' => 'boolean', 'cash_bonus_allowed' => 'boolean', 'active' => 'boolean', 'pto_hours' => 'decimal:2'];
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(GiftCardStock::class)->orderBy('brand');
    }

    public function isGiftCard(): bool
    {
        return $this->type === 'gift_card';
    }

    /**
     * Whether the winner picks from a list after drawing: a gift card's
     * brands, or the choices added to any other prize (candy bars, say).
     * Each pick uses up one of that brand or choice.
     */
    public function hasChoices(): bool
    {
        if ($this->isGiftCard()) {
            return true;
        }
        $stocks = $this->relationLoaded('stocks') ? $this->stocks : $this->stocks()->get();

        return $stocks->contains('active', true);
    }

    /** What the winner picks: "brand" for gift cards, "choice" for everything else. */
    public function choiceNoun(): string
    {
        return $this->isGiftCard() ? 'brand' : 'choice';
    }

    /** Whether the box count comes from the choices in stock instead of the prize's own count. */
    public function countsFromChoices(): bool
    {
        return $this->hasChoices() && $this->diminishes();
    }

    /**
     * Whether drawing this prize uses up an envelope. Non-diminishing prizes
     * (candy, snack) stay at the same count, so they only set the odds.
     * Gift cards always diminish through their brand stock.
     */
    public function diminishes(): bool
    {
        return $this->isGiftCard() || $this->diminishing;
    }

    public function dollars(): string
    {
        return money($this->value_cents);
    }
}
