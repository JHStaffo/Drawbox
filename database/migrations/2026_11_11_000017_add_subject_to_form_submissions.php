<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Who a submission is about, for reports: the form's Team Member answer, or the
// person who sent it when the form doesn't ask for one. Existing submissions are
// matched by the name in their Team Member answer.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->foreignId('subject_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->index(['subject_user_id', 'created_at']);
        });

        $names = DB::table('users')->pluck('id', 'name');
        foreach (DB::table('form_submissions')->get() as $s) {
            $answer = collect(json_decode($s->answers, true) ?: [])->firstWhere('type', 'team_member');
            $subject = $answer ? ($answer['user_id'] ?? $names[$answer['value'] ?? ''] ?? null) : $s->user_id;
            DB::table('form_submissions')->where('id', $s->id)->update(['subject_user_id' => $subject]);
        }

        // Form reports are their own permission; the Admin profile starts with it.
        $admin = DB::table('permission_profiles')->where('name', 'Admin')->first();
        if ($admin) {
            $permissions = json_decode($admin->permissions, true) ?: [];
            DB::table('permission_profiles')->where('id', $admin->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, 'form_reports'])))]);
        }
    }

    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropIndex(['subject_user_id', 'created_at']);
            $table->dropConstrainedForeignId('subject_user_id');
        });
        foreach (DB::table('permission_profiles')->get() as $profile) {
            $permissions = array_values(array_diff(json_decode($profile->permissions, true) ?: [], ['form_reports']));
            DB::table('permission_profiles')->where('id', $profile->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
