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
            'pto_hours' => 'decimal:2',
            'drawn_at' => 'datetime',
            'awarded_at' => 'datetime',
        ];
    }

    public function label(): string
    {
        return $this->brand ? "{$this->prize_name} ({$this->brand})" : $this->prize_name;
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
