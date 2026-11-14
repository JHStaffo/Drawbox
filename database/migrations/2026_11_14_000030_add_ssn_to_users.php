<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Social Security numbers, encrypted with their own key (see App\Services\Ssn).
// Only the last four digits and a keyed fingerprint sit beside it unencrypted.
return new class extends Migration
{
    private const PERMISSIONS = ['ssn_view', 'ssn_edit'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('ssn_encrypted')->nullable()->after('phone');
            $table->char('ssn_last4', 4)->nullable()->after('ssn_encrypted');
            $table->char('ssn_hash', 64)->nullable()->unique()->after('ssn_last4');
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
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['ssn_hash']);
            $table->dropColumn(['ssn_encrypted', 'ssn_last4', 'ssn_hash']);
        });
        foreach (DB::table('permission_profiles')->get() as $profile) {
            $permissions = array_values(array_diff(json_decode($profile->permissions, true) ?: [], self::PERMISSIONS));
            DB::table('permission_profiles')->where('id', $profile->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
