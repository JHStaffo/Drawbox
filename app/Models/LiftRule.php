<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiftRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['min_weight_lbs' => 'integer', 'min_steps' => 'integer', 'crew' => 'integer'];
    }

    /**
     * True when the call meets every condition this rule sets. A condition the dispatcher left blank is not met.
     * Scene and destination work either way round: a House/Hospital rule also matches a Hospital to House call.
     */
    public function matches(?string $scene, ?string $destination, ?int $steps, ?int $weight): bool
    {
        return $this->placesMatch($scene, $destination)
            && ($this->min_steps === null || ($steps !== null && $steps >= $this->min_steps))
            && ($this->min_weight_lbs === null || ($weight !== null && $weight >= $this->min_weight_lbs));
    }

    /** Each place the rule names must be one end of the call, in either order. */
    private function placesMatch(?string $scene, ?string $destination): bool
    {
        $ends = array_values(array_filter([$scene, $destination], fn ($p) => $p !== null && $p !== ''));
        foreach (array_filter([$this->scene_type, $this->destination_type]) as $place) {
            $at = array_search($place, $ends, true);
            if ($at === false) {
                return false;
            }
            unset($ends[$at]);
        }

        return true;
    }

    /** "House and Hospital (either way), Weight 350+ lbs, 10+ steps" */
    public function conditions(): string
    {
        $places = array_values(array_filter([$this->scene_type, $this->destination_type]));

        return collect([
            match (count($places)) {
                2 => "{$places[0]} and {$places[1]} (either way)",
                1 => "{$places[0]} (scene or destination)",
                default => null,
            },
            $this->min_weight_lbs !== null ? "Weight {$this->min_weight_lbs}+ lbs" : null,
            $this->min_steps !== null ? "{$this->min_steps}+ steps" : null,
        ])->filter()->implode(', ');
    }
}
