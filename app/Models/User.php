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

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** Roles rank in order, so a supervisor can do everything a dispatcher can. */
    public function hasRole(string $role): bool
    {
        return array_search($this->role, self::ROLES, true) >= array_search($role, self::ROLES, true);
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
