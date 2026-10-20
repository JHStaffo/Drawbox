<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prize extends Model
{
    protected $guarded = [];

    public const TYPES = [
        'item' => 'Item',
        'pto' => 'PTO',
        'gift_card' => 'Gift card',
    ];

    protected function casts(): array
    {
        return ['diminishing' => 'boolean', 'taxable' => 'boolean', 'cash_bonus_allowed' => 'boolean', 'active' => 'boolean', 'pto_hours' => 'decimal:2'];
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
