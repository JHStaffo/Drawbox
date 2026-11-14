<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// RemoteLock: each Team Member can be linked to one RemoteLock access user,
// and each permission profile can list the door groups new Team Members get.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('remotelock_id', 64)->nullable()->unique()->after('phone');
            $table->string('remotelock_name')->nullable()->after('remotelock_id');
            // Set when a change still has to reach RemoteLock (the scheduler retries it).
            $table->boolean('remotelock_pending')->default(false)->after('remotelock_name');
            $table->string('remotelock_error', 500)->nullable()->after('remotelock_pending');
            $table->timestamp('remotelock_synced_at')->nullable()->after('remotelock_error');
        });
        Schema::table('permission_profiles', function (Blueprint $table) {
            // {door group id: name}
            $table->json('remotelock_door_groups')->nullable();
        });

        // Only Admin starts with the new permission.
        $admin = DB::table('permission_profiles')->where('name', 'Admin')->first();
        if ($admin) {
            $permissions = json_decode($admin->permissions, true) ?: [];
            $permissions = array_values(array_unique([...$permissions, 'remotelock']));
            DB::table('permission_profiles')->where('id', $admin->id)->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['remotelock_id']);
            $table->dropColumn(['remotelock_id', 'remotelock_name', 'remotelock_pending', 'remotelock_error', 'remotelock_synced_at']);
        });
        Schema::table('permission_profiles', function (Blueprint $table) {
            $table->dropColumn('remotelock_door_groups');
        });
        foreach (DB::table('permission_profiles')->get() as $profile) {
            $permissions = array_values(array_diff(json_decode($profile->permissions, true) ?: [], ['remotelock']));
            DB::table('permission_profiles')->where('id', $profile->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
