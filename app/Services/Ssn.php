<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Social Security numbers. They are encrypted with their own key (SSN_KEY in
 * .env), not APP_KEY, so the app key can be replaced without losing them and
 * a copy of the database alone shows nothing. Three columns are kept:
 *
 *  - ssn_encrypted: the nine digits, AES-256-GCM.
 *  - ssn_last4: shown masked (•••-••-1234) to people allowed to see SSNs.
 *  - ssn_hash: a keyed fingerprint (HMAC-SHA256), so the same number can be
 *    found or matched (duplicates now, OIG exclusion checks later) without
 *    decrypting every row. It can't be turned back into the number.
 *
 * Code that needs the number itself (an OIG/LEIE check, say) calls
 * $user->ssn(), and should write its own audit log entry when it does.
 */
class Ssn
{
    public static function configured(): bool
    {
        return self::key() !== null;
    }

    /** "123-45-6789", "123 45 6789" or "123456789" as nine digits, or null when it can't be a real SSN. */
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/[\s\-.]/', '', trim((string) $value));
        if (! preg_match('/^(\d{3})(\d{2})(\d{4})$/', $digits, $m)) {
            return null;
        }
        // Never issued: area 000, 666 or 900-999 (ITINs), group 00, serial 0000.
        if ($m[1] === '000' || $m[1] === '666' || $m[1][0] === '9' || $m[2] === '00' || $m[3] === '0000') {
            return null;
        }

        return $digits;
    }

    public static function format(string $digits): string
    {
        return substr($digits, 0, 3).'-'.substr($digits, 3, 2).'-'.substr($digits, 5);
    }

    public static function mask(?string $last4): string
    {
        return $last4 ? '•••-••-'.$last4 : '';
    }

    public static function encrypt(string $digits): string
    {
        return self::encrypter()->encryptString($digits);
    }

    public static function decrypt(string $stored): string
    {
        return self::encrypter()->decryptString($stored);
    }

    /** The lookup fingerprint for nine digits. */
    public static function hash(string $digits): string
    {
        return hash_hmac('sha256', $digits, hash_hmac('sha256', 'ssn-lookup', self::requireKey(), true));
    }

    /** A new key in the form .env expects. */
    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    private static function encrypter(): Encrypter
    {
        return new Encrypter(self::requireKey(), 'aes-256-gcm');
    }

    private static function requireKey(): string
    {
        return self::key() ?? throw new RuntimeException('SSN_KEY is not set.');
    }

    private static function key(): ?string
    {
        $key = (string) config('app.ssn_key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: '';
        }

        return strlen($key) === 32 ? $key : null;
    }
}
