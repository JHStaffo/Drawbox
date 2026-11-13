<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Courses written by Claude from a few details (and optionally a PDF or pasted
// text). The cron job does the writing; the result is a draft course to review.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('topic');
            // audience, goals, details, lessons, questions, passing_score, add_source_lesson.
            $table->json('brief');
            $table->string('source_path')->nullable();
            $table->string('source_name')->nullable();
            $table->string('source_mime', 120)->nullable();
            // queued, working, done or failed.
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('course_id')->nullable()->constrained('training_courses')->nullOnDelete();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_generations');
    }
};
