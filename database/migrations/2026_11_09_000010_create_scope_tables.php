<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Equipment and drugs a call may need, and the level of service each one requires.
        Schema::create('scope_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind'); // equipment, drug
            $table->string('level'); // bls, als, cct
            $table->string('note')->nullable();
            $table->timestamps();
        });

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
