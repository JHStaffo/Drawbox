<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A form's own Slack message, typed freely with $answer1, $answer2 ... filled in
// from the answers. Each submission keeps a copy of the message it was sent with.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->text('slack_template')->nullable()->after('slack_channel_name');
        });
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->text('slack_template')->nullable()->after('answers');
        });
    }

    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropColumn('slack_template');
        });
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn('slack_template');
        });
    }
};
