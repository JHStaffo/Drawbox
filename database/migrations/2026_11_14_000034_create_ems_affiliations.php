<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Virginia EMS affiliations: each active Team Member searched on the Virginia
// Office of EMS provider search, the agencies they're listed with, and the
// review of any agency besides ours.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ems_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete(); // null: the monthly automatic check
            $table->string('status', 20)->default('queued'); // queued, running, done, failed
            $table->string('error')->nullable();
            $table->unsignedInteger('members_total')->default(0);
            $table->unsignedInteger('members_done')->default(0);
            $table->unsignedInteger('found_count')->default(0);
            $table->unsignedInteger('not_found_count')->default(0); // not found, and not an accepted exclusion
            $table->unsignedInteger('excluded_count')->default(0); // not found, accepted exclusion
            $table->unsignedInteger('missing_ours_count')->default(0); // found, but not listed with our agency
            $table->unsignedInteger('review_count')->default(0); // other agencies waiting for a review
            $table->unsignedInteger('unacceptable_count')->default(0);
            $table->unsignedInteger('problem_count')->default(0); // search failed or more than one match
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ems_check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ems_check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name'); // as it was when checked
            $table->string('searched_by', 20); // name, cert
            $table->string('status', 20); // found, not_found, multiple, failed
            $table->string('cert', 30)->nullable(); // the certification # that was found
            $table->string('level')->nullable();
            $table->string('expires')->nullable();
            $table->boolean('has_ours')->default(false);
            $table->json('agencies')->nullable(); // every agency listed, as written on the site
            $table->json('new_agencies')->nullable(); // agencies first seen on this check
            $table->json('unacceptable_agencies')->nullable();
            $table->json('review_agencies')->nullable(); // waiting for a review when checked
            $table->json('providers')->nullable(); // with "multiple": everyone the search matched
            $table->boolean('accepted_exclusion')->default(false); // marked "accepted exclusion" when checked
            $table->boolean('exclusion_found')->default(false); // an accepted exclusion who was found
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index(['ems_check_id', 'status']);
        });

        // Every agency each Team Member has been listed with: when it was first
        // and last seen, and whether it's acceptable.
        Schema::create('ems_affiliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('agency');
            $table->string('agency_key');
            $table->string('status', 20)->default('pending'); // pending, acceptable, unacceptable
            $table->boolean('auto_flagged')->default(false); // unacceptable because it's on the list, not by review
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('gone_at')->nullable(); // no longer listed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'agency_key']);
            $table->index(['status', 'gone_at']);
        });

        Schema::create('ems_unacceptable_agencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('agency_key')->unique();
            $table->string('note', 1000)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('ems_cert', 30)->nullable(); // Virginia EMS certification #, searched instead of the name when set
            $table->timestamp('ems_exclusion_at')->nullable(); // "accepted exclusion": not expected on the provider search
            $table->foreignId('ems_exclusion_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ems_exclusion_note', 1000)->nullable();
            $table->timestamp('ems_exclusion_found_at')->nullable(); // an accepted exclusion who later showed up on the search
        });

        $admin = DB::table('permission_profiles')->where('name', 'Admin')->first();
        if ($admin) {
            $permissions = json_decode($admin->permissions, true) ?: [];
            $permissions = array_values(array_unique(array_merge($permissions, ['ems_affiliations'])));
            DB::table('permission_profiles')->where('id', $admin->id)->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ems_exclusion_by');
            $table->dropColumn(['ems_cert', 'ems_exclusion_at', 'ems_exclusion_note', 'ems_exclusion_found_at']);
        });
        Schema::dropIfExists('ems_unacceptable_agencies');
        Schema::dropIfExists('ems_affiliations');
        Schema::dropIfExists('ems_check_results');
        Schema::dropIfExists('ems_checks');
        foreach (DB::table('permission_profiles')->get() as $profile) {
            $permissions = array_values(array_diff(json_decode($profile->permissions, true) ?: [], ['ems_affiliations']));
            DB::table('permission_profiles')->where('id', $profile->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
