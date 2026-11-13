<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The Wellness module. Its tables all start with wellness_ and only point at
// users, so nothing else in Pulse depends on them.
return new class extends Migration
{
    /** The starting point rules from the program brief (November 2026). */
    private const RULES = [
        // key, name, category, metric, threshold, unit, points, per unit, max per day, cap, cap period, needs a note
        ['meals', 'Food tracking', 'daily', 'meals', 1, 'meals', 1, true, 3, null, null, false, 'Record each meal: 1 point per meal, up to 3 a day.'],
        ['sleep', 'Sleep', 'daily', 'sleep_minutes', 360, 'minutes', 1, false, 1, null, null, false, 'At least 6 hours of sleep. Sleep counts toward the day you wake up.'],
        ['workout', 'Workout', 'daily', 'exercise_minutes', 30, 'minutes', 1, false, 1, null, null, false, 'At least 30 minutes of exercise in the day.'],
        ['steps', 'Steps', 'daily', 'steps', 10000, 'steps', 1, false, 1, null, null, false, 'At least 10,000 steps.'],
        ['water', 'Water', 'daily', 'water_oz', 64, 'oz', 1, false, 1, null, null, false, 'At least 64 oz of water.'],
        ['meal_plan', 'Meal plan', 'daily', 'meal_plan', 1, null, 1, false, 1, null, null, false, 'You confirm you followed your meal plan for the day.'],
        ['self_care', 'Self-care visit', 'other', null, null, null, 10, false, null, 10, 'week', true, 'Massage, therapy, chiropractic or another approved self-care visit.'],
        ['inbody', 'InBody improvement', 'other', null, null, null, 30, false, null, 30, 'month', false, 'An InBody measurement that shows a qualifying improvement.'],
        ['eye', 'Eye doctor visit', 'other', null, null, null, 100, false, null, 100, 'year', true, 'One eye doctor visit a year.'],
        ['dentist', 'Dentist visit', 'other', null, null, null, 185, false, null, 370, 'year', true, 'Up to two dentist visits a year.'],
        ['primary_care', 'Primary care visit', 'other', null, null, null, 365, false, null, 365, 'year', true, 'One primary care visit a year.'],
        ['weigh_in', 'Weekly weigh-in', 'other', null, null, null, 10, false, null, 10, 'week', false, 'One weigh-in a week.'],
    ];

    public function up(): void
    {
        Schema::create('wellness_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('joined_at');
            // The rules version (date) and privacy notice the Team Member agreed to.
            $table->string('consent_version', 20);
            $table->timestamp('consent_at');
            $table->string('status', 20)->default('active'); // active or withdrawn
            $table->timestamp('withdrawn_at')->nullable();
            // An approved exception: steps, sleep and workout may be typed in by hand until this date.
            $table->date('manual_device_until')->nullable();
            $table->string('exception_note')->nullable();
            $table->timestamps();
        });

        // A rule never changes once days are scored with it: a change adds a new
        // version that starts on a later date.
        Schema::create('wellness_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40);
            $table->date('effective_from');
            $table->string('name');
            $table->string('category', 10); // daily or other
            $table->string('metric', 30)->nullable();
            $table->decimal('threshold', 10, 2)->nullable();
            $table->string('unit', 20)->nullable();
            $table->unsignedInteger('points');
            $table->boolean('per_unit')->default(false);
            $table->unsignedInteger('max_per_day')->nullable();
            $table->unsignedInteger('cap_points')->nullable();
            $table->string('cap_period', 10)->nullable(); // week, month or year
            $table->boolean('needs_note')->default(false);
            $table->string('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['key', 'effective_from']);
        });

        // What came in: one value per Team Member, day, metric and source. A new
        // sync from the same source replaces its value; sources are never added together.
        Schema::create('wellness_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('metric', 30);
            $table->decimal('value', 10, 2);
            $table->string('source', 20); // manual, apple_health, fitbit, admin
            $table->string('device', 100)->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'day', 'metric', 'source']);
            $table->index(['day', 'metric']);
        });

        // The points ledger. "earned" rows are worked out from readings while a
        // day is open and frozen once it locks; "adjustment" rows are added by
        // an admin with a reason and never changed.
        Schema::create('wellness_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->foreignId('rule_id')->nullable()->constrained('wellness_rules')->nullOnDelete();
            $table->string('activity', 40);
            $table->string('category', 10);
            $table->integer('points');
            $table->string('kind', 20)->default('earned');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'day']);
            $table->index(['day', 'category']);
        });

        $now = now();
        foreach (self::RULES as $i => [$key, $name, $category, $metric, $threshold, $unit, $points, $perUnit, $maxPerDay, $cap, $period, $note, $description]) {
            DB::table('wellness_rules')->insert([
                'key' => $key, 'effective_from' => '2026-11-01', 'name' => $name, 'category' => $category,
                'metric' => $metric, 'threshold' => $threshold, 'unit' => $unit, 'points' => $points,
                'per_unit' => $perUnit, 'max_per_day' => $maxPerDay, 'cap_points' => $cap, 'cap_period' => $period,
                'needs_note' => $note, 'description' => $description, 'position' => $i + 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Admin gets the admin side so the module can be set up; nobody joins until their profile gets "wellness".
        $admin = DB::table('permission_profiles')->where('name', 'Admin')->first();
        if ($admin) {
            $permissions = json_decode($admin->permissions, true) ?: [];
            $permissions = array_values(array_unique(array_merge($permissions, ['wellness_review', 'wellness_admin', 'wellness_settings'])));
            DB::table('permission_profiles')->where('id', $admin->id)->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wellness_points');
        Schema::dropIfExists('wellness_readings');
        Schema::dropIfExists('wellness_rules');
        Schema::dropIfExists('wellness_participants');
    }
};
