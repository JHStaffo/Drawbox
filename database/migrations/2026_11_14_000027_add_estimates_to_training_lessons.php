<?php

use App\Training\Estimate;
use App\Training\Models\Lesson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How long each lesson should take, worked out from its content (see App\Training\Estimate),
// and its words for the reference search on My Training.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_lessons', function (Blueprint $table) {
            $table->unsignedInteger('est_seconds')->default(0)->after('min_seconds');
            $table->string('est_basis')->nullable()->after('est_seconds');
            // Title and page text as plain words, for searching completed courses.
            $table->longText('search_text')->nullable();
        });
        foreach (Lesson::all() as $lesson) {
            $e = Estimate::lesson($lesson);
            Lesson::whereKey($lesson->id)->update(['est_seconds' => $e['seconds'], 'est_basis' => $e['basis'] ?: null, 'search_text' => $lesson->searchText()]);
        }
    }

    public function down(): void
    {
        Schema::table('training_lessons', function (Blueprint $table) {
            $table->dropColumn(['est_seconds', 'est_basis', 'search_text']);
        });
    }
};
