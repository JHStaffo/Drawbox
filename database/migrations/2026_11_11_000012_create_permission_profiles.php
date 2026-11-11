<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Permission profiles replace the four fixed roles. The starting profiles
// match what each role could do, and everyone keeps the access they had.
return new class extends Migration
{
    private const START = [
        'employee' => ['Team Member', []],
        'dispatcher' => ['Dispatcher', ['credit_calls', 'scope_check']],
        'supervisor' => ['Supervisor', ['credit_calls', 'scope_check', 'dashboard']],
        'admin' => ['Admin', ['credit_calls', 'scope_check', 'dashboard', 'reports', 'inventory', 'lockers', 'edit_won', 'team_members', 'settings', 'audit_log', 'profiles']],
    ];

    public function up(): void
    {
        Schema::create('permission_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('permissions');
            $table->boolean('is_default')->default(false);
            $table->boolean('hide_winners')->default(false);
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('permission_profile_id')->nullable()->after('employee_number')->constrained('permission_profiles');
        });

        $hidden = DB::table('settings')->where('key', 'like', 'news_hide_%')->pluck('value', 'key');
        foreach (self::START as $role => [$name, $permissions]) {
            $id = DB::table('permission_profiles')->insertGetId([
                'name' => $name,
                'permissions' => json_encode($permissions),
                'is_default' => $role === 'employee',
                'hide_winners' => ($hidden["news_hide_{$role}"] ?? '0') === '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('role', $role)->update(['permission_profile_id' => $id]);
        }
        $default = DB::table('permission_profiles')->where('is_default', true)->value('id');
        DB::table('users')->whereNull('permission_profile_id')->update(['permission_profile_id' => $default]);
        DB::table('settings')->where('key', 'like', 'news_hide_%')->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('employee');
        });
        $roles = array_flip(array_map(fn ($p) => $p[0], self::START));
        foreach (DB::table('permission_profiles')->get() as $profile) {
            DB::table('users')->where('permission_profile_id', $profile->id)->update(['role' => $roles[$profile->name] ?? 'employee']);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('permission_profile_id');
        });
        Schema::dropIfExists('permission_profiles');
    }
};
