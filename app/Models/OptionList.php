<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A shared list, like Facilities, that form questions pick from. */
class OptionList extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['allow_add' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OptionListItem::class)->orderBy('name');
    }

    /** @return list<string> names shown to people filling out a form, A to Z */
    public function activeNames(): array
    {
        return $this->items->where('active', true)->pluck('name')->values()->all();
    }

    /** The existing entry with this name, ignoring capitals and extra spaces. */
    public function find(string $name): ?OptionListItem
    {
        $key = self::normalize($name);

        return $this->items()->get()->first(fn (OptionListItem $i) => self::normalize($i->name) === $key);
    }

    public static function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }
}
