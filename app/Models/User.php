<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'employee_number', 'permission_profile_id', 'active'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_login_at' => 'datetime'];
    }

    /** True when this person's permission profile includes the permission. */
    public function hasPermission(string $permission): bool
    {
        return (bool) $this->profile?->allows($permission);
    }

    /** True when they can open at least one page in the Admin menu. */
    public function hasAnyPermission(string ...$permissions): bool
    {
        return collect($permissions)->contains(fn ($p) => $this->hasPermission($p));
    }

    /** Every permission this person has, through their profile and its parent. */
    public function permissions(): array
    {
        return $this->profile?->effectivePermissions() ?? [];
    }

    /**
     * True when the permissions can be handed out by this person: nobody can
     * give a profile, or a team member, anything they don't have themselves.
     * Admins have every permission, so they can give anything.
     */
    public function canGrant(array $permissions): bool
    {
        return array_diff($permissions, $this->permissions()) === [];
    }

    /** True when they may put a team member on this profile. */
    public function canGrantProfile(PermissionProfile $profile): bool
    {
        return $this->canGrant($profile->effectivePermissions());
    }

    /**
     * True when they may change, duplicate or delete this profile. A change to
     * a main profile reaches its sub-profiles, so those count too.
     */
    public function canEditProfile(PermissionProfile $profile): bool
    {
        return $this->canGrantProfile($profile)
            && $profile->children()->get()->every(fn (PermissionProfile $child) => $this->canGrantProfile($child));
    }

    /** True when they may edit or delete this team member: never someone with more access than them. */
    public function canManage(User $other): bool
    {
        return $this->canGrant($other->permissions());
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PermissionProfile::class, 'permission_profile_id');
    }

    /** Sort key for lists in last-name order: "Smith John" for "John Smith". */
    public function sortName(): string
    {
        $parts = preg_split('/\s+/', trim($this->name));
        $last = array_pop($parts);

        return mb_strtolower(trim($last.' '.implode(' ', $parts)));
    }

    /** @param  \Illuminate\Support\Collection<int, User>  $users */
    public static function byLastName($users)
    {
        return $users->sortBy(fn (User $u) => $u->sortName())->values();
    }

    public function credits(): HasMany
    {
        return $this->hasMany(DrawCredit::class);
    }

    public function draws(): HasMany
    {
        return $this->hasMany(Draw::class);
    }

    public function loginCodes(): HasMany
    {
        return $this->hasMany(LoginCode::class);
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }
}
