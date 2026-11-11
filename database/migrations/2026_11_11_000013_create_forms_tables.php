<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Forms that post to Slack, in place of Google Forms, the Google Sheet and Zapier.
// Each form sends its submissions to one Slack channel, and every submission is
// kept here with a copy of the questions as they were asked.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slack_channel')->nullable();
            $table->string('slack_channel_name')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('type', 30);
            $table->text('options')->nullable();
            $table->string('help')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Copies of the form name and each question, so editing a form never changes what was sent.
            $table->string('form_name');
            $table->json('answers');
            // pending, sent, failed, or off (the form had no channel).
            $table->string('slack_status', 20)->default('pending');
            $table->string('slack_channel')->nullable();
            $table->string('slack_ts')->nullable();
            $table->string('slack_error')->nullable();
            $table->unsignedInteger('slack_attempts')->default(0);
            $table->timestamp('slack_sent_at')->nullable();
            $table->timestamps();
            $table->index(['slack_status', 'created_at']);
        });

        // The Admin profile gets both new permissions; everyone else starts without them.
        $admin = DB::table('permission_profiles')->where('name', 'Admin')->first();
        if ($admin) {
            $permissions = json_decode($admin->permissions, true) ?: [];
            $permissions = array_values(array_unique(array_merge($permissions, ['forms', 'manage_forms'])));
            DB::table('permission_profiles')->where('id', $admin->id)->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('forms');
        foreach (DB::table('permission_profiles')->get() as $profile) {
            $permissions = array_values(array_diff(json_decode($profile->permissions, true) ?: [], ['forms', 'manage_forms']));
            DB::table('permission_profiles')->where('id', $profile->id)->update(['permissions' => json_encode($permissions)]);
        }
        DB::table('settings')->where('key', 'slack_bot_token')->delete();
    }
};
