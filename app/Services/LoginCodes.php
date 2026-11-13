<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCode as LoginCodeNotification;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Email sign-in codes, shared by the website and the API (phone apps sign
 * in the same way: email, then the 6-digit code).
 */
class LoginCodes
{
    public const MAX_ATTEMPTS = 5;

    /**
     * Email a new code if the address belongs to an active Team Member.
     *
     * @return string sent, unknown (not on the roster or turned off) or mail_failed
     */
    public function send(string $email): string
    {
        $email = strtolower(trim($email));
        $user = User::where('email', $email)->where('active', true)->first();

        if (! $user) {
            $inactive = User::where('email', $email)->first();
            AuditLog::record('login.unknown_email', $inactive ? 'Sign-in tried for a turned-off team member.' : 'Sign-in tried with an email that is not on the roster.', $inactive, $email);

            return 'unknown';
        }

        $user->loginCodes()->whereNull('used_at')->delete();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        LoginCode::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
        ]);
        try {
            $user->notify(new LoginCodeNotification($code));
            AuditLog::record('login.code_sent', 'Sign-in code emailed.', $user);

            return 'sent';
        } catch (Throwable $e) {
            report($e);
            AuditLog::record('login.mail_failed', 'The sign-in code email could not be sent.', $user);

            return 'mail_failed';
        }
    }

    /** The Team Member the code belongs to, or null (and the failed try is logged). */
    public function verify(?string $email, string $code): ?User
    {
        $code = preg_replace('/\D/', '', $code);
        $email = $email ? strtolower(trim($email)) : null;
        $user = $email ? User::where('email', $email)->where('active', true)->first() : null;

        $login = $user?->loginCodes()
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->latest('id')->first();

        if (! $login || ! Hash::check($code, $login->code_hash)) {
            $login?->increment('attempts');
            AuditLog::record('login.failed', $login
                ? "Wrong code entered (attempt {$login->attempts} of ".self::MAX_ATTEMPTS.').'
                : 'Code entered with no valid code waiting (expired, used up, or never sent).', $user, $email);

            return null;
        }

        $login->update(['used_at' => now()]);
        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }
}
