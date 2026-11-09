<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScopeItem extends Model
{
    protected $guarded = [];

    /** Levels of service, lowest first. */
    public const LEVELS = ['bls' => 'BLS', 'als' => 'ALS', 'cct' => 'Critical Care (CCT)'];

    public const KINDS = ['equipment' => 'Equipment', 'drug' => 'Drug'];

    public function levelLabel(): string
    {
        return self::LEVELS[$this->level] ?? $this->level;
    }

    public function rank(): int
    {
        return (int) array_search($this->level, array_keys(self::LEVELS), true);
    }
}
