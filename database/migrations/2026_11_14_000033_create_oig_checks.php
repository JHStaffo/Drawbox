<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// OIG exclusion checks: each run of every active Team Member against the
// OIG's List of Excluded Individuals/Entities (LEIE), kept for the records.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oig_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete(); // null: the monthly automatic check
            $table->string('status', 20)->default('done'); // done, failed
            $table->string('error')->nullable();
            $table->timestamp('list_updated_at')->nullable(); // the OIG file's Last-Modified date
            $table->unsignedInteger('list_rows')->default(0);
            $table->unsignedInteger('members_checked')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->unsignedInteger('review_count')->default(0); // possible matches nobody has checked by hand yet
            $table->timestamps();
        });

        Schema::create('oig_check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oig_check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name'); // as it was when checked
            $table->string('dob_masked', 10)->nullable(); // 04/12/XX
            $table->string('status', 20); // clear, excluded (name and DOB match), review (name match, no DOB to compare)
            $table->json('matches')->nullable(); // the OIG list rows that matched by name (public data)
            // A person's check on the OIG website (verified with the SSN), when a match needed one.
            $table->string('review_outcome', 20)->nullable(); // excluded, not_excluded
            $table->text('review_note')->nullable();
            $table->string('review_file')->nullable(); // encrypted copy of the OIG verification page
            $table->string('review_file_name')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['oig_check_id', 'status']);
        });

        $admin = DB::table('permission_profiles')->where('name', 'Admin')->first();
        if ($admin) {
            $permissions = json_decode($admin->permissions, true) ?: [];
            $permissions = array_values(array_unique(array_merge($permissions, ['oig_exclusions'])));
            DB::table('permission_profiles')->where('id', $admin->id)->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oig_check_results');
        Schema::dropIfExists('oig_checks');
        foreach (DB::table('permission_profiles')->get() as $profile) {
            $permissions = array_values(array_diff(json_decode($profile->permissions, true) ?: [], ['oig_exclusions']));
            DB::table('permission_profiles')->where('id', $profile->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
