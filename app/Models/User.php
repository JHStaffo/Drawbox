<?php

namespace App\Models;

use App\Services\Ssn;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'employee_number', 'permission_profile_id', 'active'])]
// The SSN and date of birth columns are never in fillable (only setSsn() writes them) and never in JSON or arrays.
#[Hidden(['remember_token', 'ssn_encrypted', 'ssn_last4', 'ssn_hash', 'dob_encrypted'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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

    /**
     * A phone number as typed, in the form texting services expect
     * (+15551234567), or null when it isn't a phone number. Ten digits, or
     * eleven starting with 1, are US numbers; other countries need a leading +.
     */
    public static function normalizePhone(?string $value): ?string
    {
        $value = trim((string) $value);
        $digits = preg_replace('/\D/', '', $value);
        if (str_starts_with($value, '+')) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 && ! str_starts_with($digits, '0') ? '+'.$digits : null;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $digits) ? '+1'.$digits : null;
    }

    /** A stored phone number the way people read it: (555) 123-4567 for US numbers. */
    public static function formatPhone(?string $phone): string
    {
        if (! $phone) {
            return '';
        }

        return preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $phone, $m) ? "({$m[1]}) {$m[2]}-{$m[3]}" : $phone;
    }

    public function phoneDisplay(): string
    {
        return self::formatPhone($this->phone);
    }

    public function hasSsn(): bool
    {
        return $this->ssn_encrypted !== null;
    }

    /** •••-••-1234, or blank when there's no SSN on file. Only for people with ssn_view or ssn_edit. */
    public function ssnMasked(): string
    {
        return Ssn::mask($this->ssn_last4);
    }

    /**
     * The full SSN as 123-45-6789, decrypted. Only call this for a reason that
     * gets its own audit log entry: a permitted person looking, or a check
     * such as the OIG exclusion list.
     */
    public function ssn(): ?string
    {
        return $this->ssn_encrypted === null ? null : Ssn::format(Ssn::decrypt($this->ssn_encrypted));
    }

    /** Store nine digits (already normalized with Ssn::normalize), or null to remove it. Doesn't save. */
    public function setSsn(?string $digits): void
    {
        $this->ssn_encrypted = $digits === null ? null : Ssn::encrypt($digits);
        $this->ssn_last4 = $digits === null ? null : substr($digits, -4);
        $this->ssn_hash = $digits === null ? null : Ssn::hash($digits);
    }

    public function hasDob(): bool
    {
        return $this->dob_encrypted !== null;
    }

    /** Date of birth as Y-m-d, decrypted. The same rule as ssn(): only for a reason that gets its own audit entry. */
    public function dob(): ?string
    {
        return $this->dob_encrypted === null ? null : Ssn::decrypt($this->dob_encrypted);
    }

    /** Month and day with the year hidden (04/12/XX), or blank. Only for people with ssn_view or ssn_edit. */
    public function dobMasked(): string
    {
        if (! $this->hasDob()) {
            return '';
        }
        try {
            [, $m, $d] = explode('-', $this->dob());
        } catch (\Throwable $e) {
            return 'On file';
        }

        return "{$m}/{$d}/XX";
    }

    /** Store a Y-m-d date (from Ssn::normalizeDob), or null to remove it. Doesn't save. */
    public function setDob(?string $date): void
    {
        $this->dob_encrypted = $date === null ? null : Ssn::encrypt($date);
    }

    /** Who already has this SSN (nine digits), if anyone. */
    public static function withSsn(string $digits): ?self
    {
        return static::where('ssn_hash', Ssn::hash($digits))->first();
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }
}
