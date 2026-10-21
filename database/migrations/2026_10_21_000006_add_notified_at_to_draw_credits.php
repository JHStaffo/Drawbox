<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // New-draw emails now go out in a batch every two hours instead of right away.
        Schema::table('draw_credits', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('reminded_at');
        });

        // Credits from before this change were already emailed when they were made.
        DB::table('draw_credits')->update(['notified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('draw_credits', fn (Blueprint $table) => $table->dropColumn('notified_at'));
    }
};
