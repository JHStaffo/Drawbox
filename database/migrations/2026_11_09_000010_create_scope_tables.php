<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Equipment and meds a call may need, and the level of care each one requires.
        Schema::create('scope_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('level'); // bls, als, sct, rn
            $table->string('note')->nullable();
            $table->timestamps();
        });

        // Fast Track's starting list (James, 2026-11-09). Admins edit it in Settings.
        $now = now();
        foreach ([
            'rn' => ['REBOA', 'ECMO', 'IABP'],
            'sct' => ['Arterial pressure monitoring / invasive monitoring', 'ICP monitoring', 'High flow', '6+ IV meds'],
            'als' => ['Cardiac monitoring', 'Initiate/manage ventilator', 'Other IV meds', 'Chest tube', 'Intubation', 'Cardiac pacing'],
        ] as $level => $names) {
            foreach ($names as $name) {
                DB::table('scope_items')->insert(['name' => $name, 'level' => $level, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // Lift assist policy: when every condition a rule sets is met, the call needs at least its crew and trucks.
        Schema::create('lift_rules', function (Blueprint $table) {
            $table->id();
            $table->string('scene_type')->nullable();
            $table->string('destination_type')->nullable();
            $table->unsignedInteger('min_weight_lbs')->nullable();
            $table->unsignedInteger('min_steps')->nullable();
            $table->unsignedTinyInteger('crew');
            $table->unsignedTinyInteger('trucks');
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lift_rules');
        Schema::dropIfExists('scope_items');
    }
};
