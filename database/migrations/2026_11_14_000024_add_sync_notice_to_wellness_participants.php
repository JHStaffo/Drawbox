<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When a Team Member was last emailed that their wearable stopped syncing, so
// they get one email per gap instead of one a day.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wellness_participants', function (Blueprint $table) {
            $table->timestamp('sync_notice_at')->nullable()->after('exception_note');
        });
    }

    public function down(): void
    {
        Schema::table('wellness_participants', function (Blueprint $table) {
            $table->dropColumn('sync_notice_at');
        });
    }
};
