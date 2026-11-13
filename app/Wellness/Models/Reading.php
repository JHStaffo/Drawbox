<?php

namespace App\Wellness\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One value for one day and metric from one source (typed in, Apple Health, Fitbit...). */
class Reading extends Model
{
    protected $table = 'wellness_readings';

    protected $guarded = [];

    /** What can be recorded, with the units each is kept in. */
    public const METRICS = [
        'meals' => ['Meals recorded', 'meals'],
        'sleep_minutes' => ['Sleep', 'minutes'],
        'exercise_minutes' => ['Workout', 'minutes'],
        'steps' => ['Steps', 'steps'],
        'water_oz' => ['Water', 'oz'],
        'meal_plan' => ['Meal plan followed', 'yes/no'],
    ];

    /** Metrics a wearable measures; typing them in needs the testing setting or an exception. */
    public const DEVICE_METRICS = ['sleep_minutes', 'exercise_minutes', 'steps'];

    /** Highest value we accept per day, to catch typos and bad syncs. */
    public const LIMITS = [
        'meals' => 10, 'sleep_minutes' => 1440, 'exercise_minutes' => 1440, 'steps' => 150000, 'water_oz' => 400, 'meal_plan' => 1,
    ];

    public const SOURCES = [
        'manual' => 'Typed in',
        'apple_health' => 'Apple Health',
        'fitbit' => 'Fitbit',
        'admin' => 'Entered by an admin',
    ];

    protected function casts(): array
    {
        // day stays a plain Y-m-d string so it matches the same way on MySQL and SQLite.
        return ['value' => 'float', 'recorded_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }
}
