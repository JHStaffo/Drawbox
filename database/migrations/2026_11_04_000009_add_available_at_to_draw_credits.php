<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Credited draws now wait a few hours before they can be drawn. Existing credits stay drawable (null).
        Schema::table('draw_credits', function (Blueprint $table) {
            $table->timestamp('available_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('draw_credits', fn (Blueprint $table) => $table->dropColumn('available_at'));
    }
};
