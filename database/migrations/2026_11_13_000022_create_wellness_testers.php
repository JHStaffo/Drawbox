<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Team Members given Wellness one by one for testing, on top of the
// Wellness permission on their profile. Either one lets them sign up.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wellness_testers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wellness_testers');
    }
};
