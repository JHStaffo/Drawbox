<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Shared lists, like Facilities, that any form question can use. People filling
// out a form pick from the list or type a new entry, which joins the list.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('allow_add')->default(true);
            $table->timestamps();
        });
        Schema::create('option_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_list_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['option_list_id', 'name']);
        });
        Schema::table('form_fields', function (Blueprint $table) {
            $table->foreignId('option_list_id')->nullable()->after('options')->constrained()->nullOnDelete();
        });

        DB::table('option_lists')->insert(['name' => 'Facilities', 'allow_add' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->dropConstrainedForeignId('option_list_id');
        });
        Schema::dropIfExists('option_list_items');
        Schema::dropIfExists('option_lists');
    }
};
