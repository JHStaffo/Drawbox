<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a group of people can do. Everyone can draw prizes they've earned;
 * the permissions below add pages and actions on top of that. A sub-profile
 * (EMT under Team Member, say) has everything its parent allows plus its own.
 * Sub-profiles go one level deep.
 */
class PermissionProfile extends Model
{
    protected $guarded = [];

    /**
     * Grouped the way the menus are. A new feature adds its key here; every
     * profile starts without it, and a migration can grant it where needed.
     */
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
            'forms' => 'Forms (fill out and send)',
        ],
        'Admin' => [
            'team_members' => 'Team Members',
            'settings' => 'Settings',
            'audit_log' => 'Audit log',
            'profiles' => 'Permission Profiles',
            'manage_forms' => 'Forms and Slack (build forms, see every submission, connect Slack)',
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
        return in_array($permission, $this->effectivePermissions(), true);
    }

    /** Its own permissions plus its parent's. */
    public function effectivePermissions(): array
    {
        return array_values(array_unique(array_merge($this->inheritedPermissions(), $this->permissions ?? [])));
    }

    /** What it gets from its parent, if it's a sub-profile. */
    public function inheritedPermissions(): array
    {
        return $this->parent?->permissions ?? [];
    }

    /** "Team Member › EMT" for a sub-profile, "Team Member" otherwise. */
    public function label(): string
    {
        return $this->parent ? "{$this->parent->name} › {$this->name}" : $this->name;
    }

    /** Main profiles in the order they were added, each followed by its sub-profiles A to Z. */
    public static function ordered()
    {
        $all = static::with('parent')->orderBy('id')->get();

        return $all->whereNull('parent_id')
            ->flatMap(fn (self $top) => collect([$top])->concat($all->where('parent_id', $top->id)->sortBy('name')))
            ->values();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
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
