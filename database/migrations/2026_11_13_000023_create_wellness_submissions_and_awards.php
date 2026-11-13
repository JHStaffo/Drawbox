<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Part 2 of Wellness: activities that need review (visits with a signed
// attendance note, InBody scans, weigh-ins) and the weekly $10 reward.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wellness_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('activity', 40);
            $table->date('day');
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, withdrawn
            // The attendance note or InBody printout, stored encrypted outside the web folder.
            $table->string('note_path')->nullable();
            $table->string('note_name')->nullable();
            $table->string('note_mime', 100)->nullable();
            // InBody measures, encrypted. Only what the improvement rule needs.
            $table->text('measures')->nullable();
            $table->string('comment', 500)->nullable();
            $table->foreignId('rule_id')->nullable()->constrained('wellness_rules')->nullOnDelete();
            $table->integer('points')->nullable();
            $table->string('points_note')->nullable();
            $table->foreignId('point_id')->nullable()->constrained('wellness_points')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            // A fixed-up resubmission points at the one that was rejected.
            $table->foreignId('resubmission_of')->nullable()->constrained('wellness_submissions')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'activity', 'day']);
        });

        Schema::create('wellness_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('weekly');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('points');
            $table->unsignedInteger('value_cents');
            $table->boolean('test_period')->default(false);
            $table->string('brand')->nullable();
            $table->timestamp('brand_chosen_at')->nullable();
            $table->string('status', 20)->default('pending'); // pending, sent, void
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status_note')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'kind', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wellness_awards');
        Schema::dropIfExists('wellness_submissions');
    }
};
