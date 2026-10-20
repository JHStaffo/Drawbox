<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prizes', function (Blueprint $table) {
            $table->boolean('cash_bonus_allowed')->default(false)->after('taxable');
        });

        Schema::table('draws', function (Blueprint $table) {
            $table->boolean('cash_bonus')->default(false)->after('brand');
        });
    }

    public function down(): void
    {
        Schema::table('draws', fn (Blueprint $table) => $table->dropColumn('cash_bonus'));
        Schema::table('prizes', fn (Blueprint $table) => $table->dropColumn('cash_bonus_allowed'));
    }
};
