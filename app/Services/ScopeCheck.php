<?php

namespace App\Services;

use App\Models\LiftRule;
use App\Models\ScopeItem;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Works out the level of care a call needs from the equipment and meds
 * requested, and the crew and trucks it needs from the lift assist rules.
 * Admins keep the equipment and meds list in Settings and the lift assist
 * rules on their own page.
 */
class ScopeCheck
{
    /** @return array{level: ?string, drivers: Collection, items: Collection, crew: int, trucks: int, rules: Collection} */
    public function check(array $itemIds, ?string $scene, ?string $destination, ?int $steps, ?int $weight): array
    {
        $items = ScopeItem::whereIn('id', $itemIds)->get();
        $top = $items->max(fn (ScopeItem $i) => $i->rank());
        $level = $top === null ? null : array_keys(ScopeItem::LEVELS)[$top];

        $rules = LiftRule::all()->filter(fn (LiftRule $r) => $r->matches($scene, $destination, $steps, $weight))->values();

        return [
            'level' => $level,
            'drivers' => $items->where('level', $level)->sortBy('name')->values(),
            'items' => $items,
            'crew' => max((int) Setting::get('scope_base_crew'), (int) $rules->max('crew')),
            'trucks' => max((int) Setting::get('scope_base_trucks'), (int) $rules->max('trucks')),
            'rules' => $rules,
        ];
    }

    /** The scene or destination choices an admin listed, one per line. */
    public static function options(string $key): array
    {
        return collect(preg_split('/\R/', Setting::get($key)))->map(fn ($l) => trim($l))->filter()->unique()->values()->all();
    }
}
