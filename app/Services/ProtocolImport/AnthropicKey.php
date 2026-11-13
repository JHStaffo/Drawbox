<?php

namespace App\Services\ProtocolImport;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * The Anthropic API key used to read uploaded protocols. It is pasted in
 * Admin > Settings and stored encrypted with the app key; ANTHROPIC_API_KEY
 * in .env is still used when nothing is saved there.
 */
class AnthropicKey
{
    public function get(): ?string
    {
        return $this->saved() ?? (config('services.anthropic.key') ?: null);
    }

    /** settings, env, or null. */
    public function source(): ?string
    {
        return $this->saved() ? 'settings' : (config('services.anthropic.key') ? 'env' : null);
    }

    public function save(?string $key): void
    {
        Setting::put('anthropic_api_key', $key ? Crypt::encryptString($key) : '');
    }

    /** "sk-ant-…f3a9", safe to show on the page. */
    public function masked(): ?string
    {
        $key = $this->get();

        return $key ? substr($key, 0, 7).'…'.substr($key, -4) : null;
    }

    /** Makes one small call to check the key works. Throws with a readable message when it doesn't. */
    public function check(string $key): void
    {
        try {
            (new Client(apiKey: $key, requestOptions: ['timeout' => 20, 'maxRetries' => 1]))
                ->models->retrieve(config('services.anthropic.model'));
        } catch (AuthenticationException|PermissionDeniedException) {
            throw new RuntimeException('Anthropic turned that key down. Copy it again from console.anthropic.com > API keys.');
        } catch (APIStatusException|APIConnectionException $e) {
            throw new RuntimeException('Couldn\'t reach Anthropic to check the key ('.$e->getMessage().'). Try again in a minute.');
        }
    }

    private function saved(): ?string
    {
        $stored = Setting::get('anthropic_api_key');
        if ($stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null; // The app key changed; the key has to be pasted again.
        }
    }
}
