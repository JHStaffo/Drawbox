<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiftRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['min_weight_lbs' => 'integer', 'min_steps' => 'integer', 'crew' => 'integer', 'trucks' => 'integer'];
    }

    /** True when the call meets every condition this rule sets. A condition the dispatcher left blank is not met. */
    public function matches(?string $scene, ?string $destination, ?int $steps, ?int $weight): bool
    {
        return ($this->scene_type === null || $this->scene_type === $scene)
            && ($this->destination_type === null || $this->destination_type === $destination)
            && ($this->min_steps === null || ($steps !== null && $steps >= $this->min_steps))
            && ($this->min_weight_lbs === null || ($weight !== null && $weight >= $this->min_weight_lbs));
    }

    /** "Weight 350+ lbs, 10+ steps" */
    public function conditions(): string
    {
        return collect([
            $this->scene_type ? "Scene: {$this->scene_type}" : null,
            $this->destination_type ? "Destination: {$this->destination_type}" : null,
            $this->min_weight_lbs !== null ? "Weight {$this->min_weight_lbs}+ lbs" : null,
            $this->min_steps !== null ? "{$this->min_steps}+ steps" : null,
        ])->filter()->implode(', ');
    }
}
