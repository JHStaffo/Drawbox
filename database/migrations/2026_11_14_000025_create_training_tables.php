<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Training: courses made of lessons (text, PDF, PowerPoint, video, audio, a link,
// a file or a quiz), assigned to Team Members one at a time or in training
// profiles. Reassigning keeps the old assignment as history and starts a new one.
return new class extends Migration
{
    private const PERMISSIONS = ['training', 'training_build', 'training_assign', 'training_reports'];

    public function up(): void
    {
        Schema::create('training_courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 80)->nullable();
            // draft (only builders see it), published (can be assigned) or archived (no new assignments).
            $table->string('status', 20)->default('draft');
            // Default days to finish once assigned; blank means no due date.
            $table->unsignedSmallInteger('due_days')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('training_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('title');
            // text, pdf, slides, video, audio, link, file or quiz.
            $table->string('type', 20);
            $table->longText('body')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('url', 1000)->nullable();
            // "Mark as done" waits until they've spent this long on the lesson.
            $table->unsignedInteger('min_seconds')->nullable();
            // Quizzes only.
            $table->unsignedTinyInteger('passing_score')->nullable();
            $table->unsignedSmallInteger('max_attempts')->nullable();
            $table->boolean('show_answers')->default(true);
            $table->boolean('shuffle')->default(false);
            $table->timestamps();
            $table->index(['course_id', 'position']);
        });

        Schema::create('training_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('training_lessons')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            // single, multiple (select all that apply) or true_false.
            $table->string('type', 20)->default('single');
            $table->text('prompt');
            // [{"text": "...", "correct": true}, ...]
            $table->json('choices');
            $table->text('explanation')->nullable();
            $table->timestamps();
        });

        Schema::create('training_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('due_days')->nullable();
            $table->timestamps();
        });

        Schema::create('training_profile_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_profile_id')->constrained('training_profiles')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unique(['training_profile_id', 'course_id']);
        });

        // Team Members on these permission profiles (or their sub-profiles) get the training profile automatically.
        Schema::create('training_profile_auto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_profile_id')->constrained('training_profiles')->cascadeOnDelete();
            $table->foreignId('permission_profile_id')->constrained('permission_profiles')->cascadeOnDelete();
            $table->unique(['training_profile_id', 'permission_profile_id'], 'training_profile_auto_unique');
        });

        Schema::create('training_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('training_profile_id')->nullable()->constrained('training_profiles')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('seconds_spent')->default(0);
            $table->unsignedTinyInteger('percent')->default(0);
            // Best quiz score (the average when a course has more than one quiz).
            $table->unsignedTinyInteger('score')->nullable();
            // Set when it was reassigned: the old one stays as history.
            $table->timestamp('replaced_at')->nullable();
            $table->foreignId('replaced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'course_id']);
            $table->index(['course_id', 'replaced_at']);
        });

        Schema::create('training_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('training_assignments')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('training_lessons')->cascadeOnDelete();
            $table->unsignedInteger('seconds_spent')->default(0);
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedTinyInteger('best_score')->nullable();
            // More quiz attempts given by a trainer after they ran out.
            $table->unsignedSmallInteger('extra_attempts')->default(0);
            $table->timestamps();
            $table->unique(['assignment_id', 'lesson_id']);
        });

        Schema::create('training_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('training_assignments')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('training_lessons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('seconds')->nullable();
            // The question order they were shown, then each question as it was with their answer.
            $table->json('question_ids');
            $table->json('answers')->nullable();
            $table->unsignedSmallInteger('correct')->nullable();
            $table->unsignedSmallInteger('total')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->boolean('passed')->default(false);
            $table->timestamps();
            $table->index(['assignment_id', 'lesson_id']);
        });

        // Admin gets the new permissions so it can hand them out; everyone else starts without them.
        $admin = DB::table('permission_profiles')->where('name', 'Admin')->first();
        if ($admin) {
            $permissions = json_decode($admin->permissions, true) ?: [];
            $permissions = array_values(array_unique(array_merge($permissions, self::PERMISSIONS)));
            DB::table('permission_profiles')->where('id', $admin->id)->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_attempts');
        Schema::dropIfExists('training_lesson_progress');
        Schema::dropIfExists('training_assignments');
        Schema::dropIfExists('training_profile_auto');
        Schema::dropIfExists('training_profile_courses');
        Schema::dropIfExists('training_profiles');
        Schema::dropIfExists('training_questions');
        Schema::dropIfExists('training_lessons');
        Schema::dropIfExists('training_courses');
        foreach (DB::table('permission_profiles')->get() as $profile) {
            $permissions = array_values(array_diff(json_decode($profile->permissions, true) ?: [], self::PERMISSIONS));
            DB::table('permission_profiles')->where('id', $profile->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
