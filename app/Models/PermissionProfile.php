<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a group of people can do. Everyone can draw prizes they've earned;
 * the permissions below add pages and actions on top of that.
 */
class PermissionProfile extends Model
{
    protected $guarded = [];

    /** Grouped the way the menus are. */
    public const GROUPS = [
        'Draw Box' => [
            'credit_calls' => 'Credit a call',
            'dashboard' => 'Dashboard (hand out prizes, check lockers, remove unused draws)',
            'reports' => 'Reports',
            'inventory' => 'Inventory',
            'lockers' => 'Locker map',
            'edit_won' => 'Change or remove won prizes',
        ],
        'Dispatch' => [
            'scope_check' => 'Scope Check',
        ],
        'Admin' => [
            'team_members' => 'Team Members',
            'settings' => 'Settings',
            'audit_log' => 'Audit log',
            'profiles' => 'Permission Profiles',
        ],
    ];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_default' => 'boolean', 'hide_winners' => 'boolean'];
    }

    /** @return array<string, string> key => label */
    public static function permissionLabels(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? [], true);
    }

    /** The profile new and imported team members get. */
    public static function default(): ?self
    {
        return static::where('is_default', true)->first() ?? static::orderBy('id')->first();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
