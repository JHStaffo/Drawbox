<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScopeItem extends Model
{
    protected $guarded = [];

    /** Levels of care, lowest first. */
    public const LEVELS = ['bls' => 'BLS', 'als' => 'ALS', 'sct' => 'Preferred SCT/ALS', 'rn' => 'RN Required'];

    public function levelLabel(): string
    {
        return self::LEVELS[$this->level] ?? $this->level;
    }

    public function rank(): int
    {
        return (int) array_search($this->level, array_keys(self::LEVELS), true);
    }
}
