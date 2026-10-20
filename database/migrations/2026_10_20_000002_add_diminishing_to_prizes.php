<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Replace the "always in the box" prize type with a per-prize checkbox. */
    public function up(): void
    {
        Schema::table('prizes', function (Blueprint $table) {
            $table->boolean('diminishing')->default(true)->after('type');
        });

        DB::table('prizes')->where('type', 'constant')->update(['type' => 'item', 'diminishing' => false, 'monthly_quantity' => 0]);
        DB::table('draws')->where('prize_type', 'constant')->update(['prize_type' => 'item']);
    }

    public function down(): void
    {
        DB::table('prizes')->where('diminishing', false)->where('type', 'item')->update(['type' => 'constant']);

        Schema::table('prizes', function (Blueprint $table) {
            $table->dropColumn('diminishing');
        });
    }
};
