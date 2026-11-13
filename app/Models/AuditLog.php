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
        'supervisor.reports.pto.csv' => 'Exported PTO earned',
        'supervisor.reports.prizes' => 'Exported prize report',
        'supervisor.reports.employees' => 'Exported team member totals',
        'supervisor.inventory.reset' => 'Reset draws left',
        'supervisor.inventory.restock' => 'Restocked the box',
        'supervisor.inventory.prizes.store' => 'Added a prize',
        'supervisor.inventory.prizes.update' => 'Changed a prize',
        'supervisor.inventory.stocks.store' => 'Added brands or choices',
        'supervisor.inventory.stocks.update' => 'Changed a brand or choice',
        'supervisor.inventory.counts.update' => 'Changed counts',
        'supervisor.inventory.prizes.toggle' => 'Hid or unhid a prize',
        'supervisor.inventory.prizes.destroy' => 'Deleted a prize',
        'supervisor.inventory.stocks.toggle' => 'Hid or unhid a brand or choice',
        'supervisor.inventory.stocks.destroy' => 'Deleted a brand or choice',
        'supervisor.settings.update' => 'Changed settings',
        'admin.scope.items.store' => 'Added a Scope Check item',
        'admin.scope.items.update' => 'Changed a Scope Check item',
        'admin.scope.items.destroy' => 'Deleted a Scope Check item',
        'admin.scope.rules.store' => 'Added a lift assist rule',
        'admin.scope.rules.update' => 'Changed a lift assist rule',
        'admin.scope.rules.destroy' => 'Deleted a lift assist rule',
        'admin.scope.options' => 'Changed scope check options',
        'admin.users.store' => 'Added a team member',
        'admin.users.import' => 'Imported team members',
        'admin.users.update' => 'Changed a team member',
        'admin.users.destroy' => 'Deleted a team member',
        'admin.profiles.store' => 'Added a permission profile',
        'admin.profiles.update' => 'Changed a permission profile',
        'admin.profiles.duplicate' => 'Duplicated a permission profile',
        'admin.profiles.default' => 'Changed the profile for new team members',
        'admin.profiles.destroy' => 'Deleted a permission profile',
        'admin.draws.update' => 'Changed a won prize',
        'admin.draws.destroy' => 'Removed a won prize',
        'admin.make-admin' => 'Made an admin (server)',
        'forms.store' => 'Sent a form',
        'forms.reports.csv' => 'Exported form report',
        'admin.forms.store' => 'Added a form',
        'admin.forms.update' => 'Changed a form',
        'admin.forms.destroy' => 'Deleted a form',
        'admin.forms.test' => 'Sent a test form to Slack',
        'admin.forms.fields.store' => 'Added a form question',
        'admin.forms.fields.update' => 'Changed a form question',
        'admin.forms.fields.destroy' => 'Removed a form question',
        'admin.forms.fields.move' => 'Reordered a form question',
        'admin.forms.resend' => 'Posted a form to Slack by hand',
        'admin.slack.update' => 'Connected Slack',
        'admin.slack.destroy' => 'Disconnected Slack',
        'admin.slack.test' => 'Sent a Slack test message',
        'system.slack-retry' => 'Form posts retried',
        'admin.lists.store' => 'Added a shared list',
        'admin.lists.update' => 'Changed a shared list',
        'admin.lists.destroy' => 'Deleted a shared list',
        'admin.lists.items.store' => 'Added to a shared list',
        'admin.lists.items.update' => 'Renamed a shared list entry',
        'admin.lists.items.toggle' => 'Hid or unhid a shared list entry',
        'admin.lists.items.destroy' => 'Removed a shared list entry',
        'wellness.join' => 'Joined Wellness',
        'wellness.withdraw' => 'Left Wellness',
        'wellness.log.save' => 'Logged wellness',
        'wellness.connect.store' => 'Made a wellness sync key',
        'wellness.connect.destroy' => 'Removed a wellness sync key',
        'wellness.admin.testers.store' => 'Gave someone Wellness for testing',
        'wellness.admin.testers.destroy' => 'Removed a Wellness tester',
        'wellness.admin.exception' => 'Changed a wellness device exception',
        'wellness.admin.rules.store' => 'Changed a wellness rule',
        'wellness.admin.settings.update' => 'Changed wellness settings',
        'api.auth.token' => 'Signed in on the app',
        'api.auth.logout' => 'Signed out of the app',
        'api.wellness.join' => 'Joined Wellness (app)',
        'api.wellness.days.update' => 'Logged wellness (app)',
        'api.wellness.readings' => 'Wellness device sync',
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
        // Before PR #27, signing in on the cached live routes also logged an extra "generated::…" row.
        if (str_starts_with($this->action, 'generated::')) {
            return 'Sign-in step (duplicate)';
        }

        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
