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
        'constant' => 'Always in the box',
        'gift_card' => 'Gift card',
    ];

    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'active' => 'boolean', 'pto_hours' => 'decimal:2'];
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(GiftCardStock::class)->orderBy('brand');
    }

    public function isGiftCard(): bool
    {
        return $this->type === 'gift_card';
    }

    /** Constant prizes (candy, snack) are never used up. */
    public function isConstant(): bool
    {
        return $this->type === 'constant';
    }

    public function dollars(): string
    {
        return money($this->value_cents);
    }
}
