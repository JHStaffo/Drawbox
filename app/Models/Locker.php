<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Locker extends Model
{
    protected $guarded = [];

    public const OFFICES = [
        'main' => 'Main',
        'newport_news' => 'Newport News',
    ];

    public function officeName(): string
    {
        return self::OFFICES[$this->office];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LockerAssignment::class);
    }

    /** The batch in the locker now: not picked up and not cleared out. */
    public function current(): HasOne
    {
        return $this->hasOne(LockerAssignment::class)->ofMany(['id' => 'max'], fn ($q) => $q->whereNull('picked_up_at')->whereNull('cleared_at'));
    }

    public function latest(): HasOne
    {
        return $this->hasOne(LockerAssignment::class)->latestOfMany();
    }

    /** Lockers for one office, ready to lay out in rows and columns. */
    public static function forOffice(string $office)
    {
        return static::where('office', $office)->orderBy('row')->orderBy('col')->get();
    }
}
