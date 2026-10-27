<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** Readable names for the actions on the audit log page. */
    public const ACTIONS = [
        'login' => 'Signed in',
        'login.code_sent' => 'Sign-in code sent',
        'login.unknown_email' => 'Sign-in tried with an unknown email',
        'login.failed' => 'Wrong sign-in code',
        'login.throttled' => 'Too many sign-in attempts',
        'login.mail_failed' => 'Sign-in email failed',
        'logout' => 'Signed out',
        'draws.store' => 'Drew a prize',
        'draws.brand' => 'Picked a brand or choice',
        'draws.cash-bonus' => 'Took a cash bonus',
        'lockers.picked-up' => 'Picked up from a locker',
        'dispatch.store' => 'Credited a call',
        'dispatch.destroy' => 'Undid a credited call',
        'supervisor.award' => 'Marked a prize received',
        'supervisor.credits.void' => 'Removed an unused draw',
        'supervisor.dispense.store' => 'Dispensed prizes',
        'supervisor.lockers.picked-up' => 'Locker checked: empty',
        'supervisor.lockers.forfeit' => 'Locker checked: forfeited',
        'supervisor.reports.won.csv' => 'Exported won prizes',
        'supervisor.reports.prizes' => 'Exported prize report',
        'supervisor.reports.employees' => 'Exported team member totals',
        'supervisor.inventory.restock' => 'Restocked the box',
        'supervisor.inventory.prizes.store' => 'Added a prize',
        'supervisor.inventory.prizes.update' => 'Changed a prize',
        'supervisor.inventory.stocks.store' => 'Added brands or choices',
        'supervisor.inventory.stocks.update' => 'Changed a brand or choice',
        'supervisor.inventory.prizes.toggle' => 'Hid or unhid a prize',
        'supervisor.inventory.prizes.destroy' => 'Deleted a prize',
        'supervisor.inventory.stocks.toggle' => 'Hid or unhid a brand or choice',
        'supervisor.inventory.stocks.destroy' => 'Deleted a brand or choice',
        'supervisor.settings.update' => 'Changed settings',
        'admin.users.store' => 'Added a team member',
        'admin.users.import' => 'Imported team members',
        'admin.users.update' => 'Changed a team member',
        'admin.users.destroy' => 'Deleted a team member',
        'admin.draws.update' => 'Changed a won prize',
        'admin.draws.destroy' => 'Removed a won prize',
        'admin.make-admin' => 'Made an admin (server)',
        'system.expire' => 'Draws expired',
        'system.notify' => 'New-draw emails sent',
        'system.remind' => 'Expiry reminders sent',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** Write one entry. Never lets a logging problem break the action itself. */
    public static function record(string $action, string $description, ?User $user = null, ?string $email = null): void
    {
        try {
            static::create([
                'user_id' => $user?->id,
                'email' => $email ?? $user?->email,
                'action' => $action,
                'description' => mb_substr($description, 0, 2000),
                'ip' => app()->runningInConsole() ? null : request()->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
