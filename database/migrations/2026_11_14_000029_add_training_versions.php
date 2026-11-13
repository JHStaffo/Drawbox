<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Course versions: once a course is published, edits wait as unpublished changes until
// someone publishes them as a new version. Records who reviewed each version, and when.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_courses', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(0)->after('status');
        });
        Schema::table('training_lessons', function (Blueprint $table) {
            // A pending edit of a published lesson; Team Members never see these rows.
            $table->foreignId('draft_of')->nullable()->after('section_id')->constrained('training_lessons')->cascadeOnDelete();
            $table->boolean('pending_add')->default(false)->after('draft_of');
            $table->boolean('pending_remove')->default(false)->after('pending_add');
            // The course version this lesson last changed in.
            $table->unsignedInteger('changed_in')->nullable()->after('pending_remove');
        });
        Schema::create('training_course_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->text('note')->nullable();
            $table->json('changes')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['course_id', 'number']);
        });
        Schema::table('training_assignments', function (Blueprint $table) {
            $table->unsignedInteger('completed_version')->nullable()->after('completed_at');
            $table->unsignedInteger('notified_version')->nullable()->after('completed_version');
        });
        Schema::create('training_version_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->timestamp('first_viewed_at');
            $table->timestamp('last_viewed_at');
            $table->unsignedInteger('views')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'course_id', 'version']);
        });

        // Courses already out there become version 1.
        foreach (DB::table('training_courses')->where('status', '!=', 'draft')->get() as $course) {
            DB::table('training_courses')->where('id', $course->id)->update(['version' => 1]);
            DB::table('training_lessons')->where('course_id', $course->id)->update(['changed_in' => 1]);
            DB::table('training_course_versions')->insert([
                'course_id' => $course->id, 'number' => 1, 'note' => 'Published before version history started.', 'changes' => '[]',
                'published_by' => $course->updated_by, 'published_at' => $course->updated_at ?? now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('training_assignments')->where('course_id', $course->id)->whereNotNull('completed_at')->update(['completed_version' => 1]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_version_views');
        Schema::table('training_assignments', fn (Blueprint $t) => $t->dropColumn(['completed_version', 'notified_version']));
        Schema::dropIfExists('training_course_versions');
        Schema::table('training_lessons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('draft_of');
            $table->dropColumn(['pending_add', 'pending_remove', 'changed_in']);
        });
        Schema::table('training_courses', fn (Blueprint $t) => $t->dropColumn('version'));
    }
};
