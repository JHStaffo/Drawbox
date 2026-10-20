<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deleted prizes are soft-deleted so past draws keep their history for tax records.
        Schema::table('prizes', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('gift_card_stocks', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('monthly_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('gift_card_stocks', fn (Blueprint $table) => $table->dropColumn('active'));
        Schema::table('prizes', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
