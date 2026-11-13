<?php

namespace App\Wellness\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * How one activity scores. Rules are versioned: a change is a new row with a
 * later effective_from date, so days already scored keep the rule they had.
 */
class Rule extends Model
{
    protected $table = 'wellness_rules';

    protected $guarded = [];

    public const CAP_PERIODS = ['week' => 'week', 'month' => 'month', 'year' => 'year'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date', 'threshold' => 'float', 'points' => 'integer', 'per_unit' => 'boolean',
            'max_per_day' => 'integer', 'cap_points' => 'integer', 'needs_note' => 'boolean',
        ];
    }

    /**
     * The version of each rule in effect on a day, keyed by rule key, in
     * display order. A day before a rule's first version uses that first version.
     *
     * @return Collection<string, Rule>
     */
    public static function forDay(string $day, ?Collection $all = null): Collection
    {
        $all ??= static::orderBy('effective_from')->get();

        return $all->groupBy('key')
            ->map(fn (Collection $versions) => $versions->filter(fn (Rule $r) => $r->effective_from->toDateString() <= $day)->last() ?? $versions->first())
            ->sortBy('position');
    }

    /** @return Collection<string, Rule> */
    public static function current(): Collection
    {
        return static::forDay(today()->toDateString());
    }

    public function isDaily(): bool
    {
        return $this->category === 'daily';
    }

    /** Points this rule gives for a day's value, before any campaign. */
    public function pointsFor(?float $value): int
    {
        if ($value === null || $this->threshold === null) {
            return 0;
        }
        if ($this->per_unit) {
            $units = (int) floor($value / max($this->threshold, 1));

            return min($units * $this->points, $this->max_per_day ?? PHP_INT_MAX);
        }

        return $value >= $this->threshold ? min($this->points, $this->max_per_day ?? PHP_INT_MAX) : 0;
    }

    /** Most points a day can give, e.g. 3 for meals. */
    public function dailyMax(): int
    {
        return $this->max_per_day ?? $this->points;
    }

    /** "10,000 steps", "6 hours", "64 oz", for the rules page. */
    public function thresholdLabel(): string
    {
        if ($this->threshold === null) {
            return '';
        }

        return match ($this->unit) {
            'minutes' => $this->threshold >= 60 && fmod($this->threshold, 60) == 0
                ? ($this->threshold / 60).' hours'
                : number_format($this->threshold).' minutes',
            null => '',
            default => number_format($this->threshold).' '.$this->unit,
        };
    }

    public function capLabel(): string
    {
        if ($this->category === 'daily') {
            return $this->dailyMax().' a day';
        }

        return $this->cap_points ? number_format($this->cap_points).' a '.$this->cap_period : 'No cap';
    }
}
