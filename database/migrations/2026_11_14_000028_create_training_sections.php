<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Named sections inside a course (Regulations, Policies, Procedures...), each holding some of its lessons.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::table('training_lessons', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('course_id')->constrained('training_sections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('training_lessons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
        });
        Schema::dropIfExists('training_sections');
    }
};
