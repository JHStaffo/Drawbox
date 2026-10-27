<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'employee_number', 'role', 'active'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLES = ['employee', 'dispatcher', 'supervisor', 'admin'];

    public const ROLE_LABELS = ['employee' => 'Team Member', 'dispatcher' => 'Dispatcher', 'supervisor' => 'Supervisor', 'admin' => 'Admin'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_login_at' => 'datetime'];
    }

    /** Roles rank in order, so a supervisor can do everything a dispatcher can. */
    public function hasRole(string $role): bool
    {
        return array_search($this->role, self::ROLES, true) >= array_search($role, self::ROLES, true);
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
