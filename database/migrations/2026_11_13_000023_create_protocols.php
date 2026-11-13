<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The protocol book. Every edit is a new version; Team Members only ever see
// the version the Medical Director approved, and approved versions never change.
return new class extends Migration
{
    private const PERMISSIONS = ['protocols', 'protocols_build', 'protocols_approve'];

    public function up(): void
    {
        Schema::create('protocol_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('protocols', function (Blueprint $table) {
            $table->id();
            // The live version (null until the first approval).
            $table->unsignedBigInteger('approved_version_id')->nullable()->index();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('protocol_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('number', 30)->nullable();
            $table->string('title');
            $table->foreignId('protocol_category_id')->nullable()->constrained()->nullOnDelete();
            // Copied so a renamed or deleted category never changes an approved version.
            $table->string('category_name')->nullable();
            $table->json('ages');
            $table->json('chart');
            $table->json('sections');
            $table->string('revised_label', 60)->nullable();
            $table->longText('search_text')->nullable();
            // draft, submitted, returned, approved, or superseded.
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // The name the Medical Director typed to sign.
            $table->string('signature')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->unique(['protocol_id', 'version']);
            $table->index('status');
        });

        $now = now();
        foreach (['Cardiac Arrest', 'Cardiac', 'Respiratory', 'Pregnancy', 'Critical Care'] as $i => $name) {
            DB::table('protocol_categories')->insert(['name' => $name, 'position' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }

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
        Schema::dropIfExists('protocol_versions');
        Schema::dropIfExists('protocols');
        Schema::dropIfExists('protocol_categories');
        foreach (DB::table('permission_profiles')->get() as $profile) {
            $permissions = array_values(array_diff(json_decode($profile->permissions, true) ?: [], self::PERMISSIONS));
            DB::table('permission_profiles')->where('id', $profile->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
