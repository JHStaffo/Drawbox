<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Lift assist only counts crew members now, not trucks.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lift_rules', function (Blueprint $table) {
            $table->dropColumn('trucks');
        });
        DB::table('settings')->where('key', 'scope_base_trucks')->delete();
    }

    public function down(): void
    {
        Schema::table('lift_rules', function (Blueprint $table) {
            $table->unsignedTinyInteger('trucks')->default(1);
        });
    }
};
