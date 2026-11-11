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
