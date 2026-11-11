<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to Slack through one Slack app's bot token (xoxb-...). The token is
 * pasted in Admin > Slack and stored encrypted with the app key. One token
 * covers every channel: public channels work right away, and private
 * channels need the app invited once with /invite.
 */
class Slack
{
    private const API = 'https://slack.com/api/';

    public function token(): ?string
    {
        $stored = Setting::get('slack_bot_token');
        if ($stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null; // The app key changed; the token has to be pasted again.
        }
    }

    public function connected(): bool
    {
        return $this->token() !== null;
    }

    public function saveToken(?string $token): void
    {
        Setting::put('slack_bot_token', $token ? Crypt::encryptString($token) : '');
        Cache::forget('slack_channels');
        Cache::forget('slack_workspace');
    }

    /** "xoxb-…f3a9", safe to show on the page. */
    public function maskedToken(): ?string
    {
        $token = $this->token();

        return $token ? substr($token, 0, 5).'…'.substr($token, -4) : null;
    }

    /**
     * Checks a token and says which workspace and bot it belongs to.
     *
     * @return array{team: string, bot: string, url: string}
     */
    public function identify(?string $token = null): array
    {
        $r = $this->call('auth.test', [], $token);

        return ['team' => $r['team'] ?? '', 'bot' => $r['user'] ?? '', 'url' => $r['url'] ?? ''];
    }

    /** The connected workspace, looked up once an hour. Null if not connected or Slack can't be reached. */
    public function workspace(): ?array
    {
        if (! $this->connected()) {
            return null;
        }
        try {
            return Cache::remember('slack_workspace', 3600, fn () => $this->identify());
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Channels the app can see, as id => name, A to Z. Private channels only
     * show once the app has been invited. Empty if the list can't be read.
     *
     * @return array<string, string>
     */
    public function channels(bool $fresh = false): array
    {
        if (! $this->connected()) {
            return [];
        }
        if ($fresh) {
            Cache::forget('slack_channels');
        }
        try {
            return Cache::remember('slack_channels', 600, function () {
                $channels = [];
                $cursor = null;
                do {
                    $r = $this->call('conversations.list', array_filter([
                        'types' => 'public_channel,private_channel',
                        'exclude_archived' => 'true',
                        'limit' => 1000,
                        'cursor' => $cursor,
                    ]));
                    foreach ($r['channels'] ?? [] as $c) {
                        $channels[$c['id']] = $c['name'];
                    }
                    $cursor = $r['response_metadata']['next_cursor'] ?? null;
                } while ($cursor);
                asort($channels, SORT_NATURAL | SORT_FLAG_CASE);

                return $channels;
            });
        } catch (RuntimeException) {
            return [];
        }
    }

    /**
     * Posts a message and returns its Slack timestamp.
     *
     * @throws RuntimeException with a message a person can act on
     */
    public function post(string $channel, string $text, array $blocks = []): string
    {
        $r = $this->call('chat.postMessage', array_filter([
            'channel' => $channel,
            'text' => $text,
            'blocks' => $blocks ?: null,
            'unfurl_links' => false,
            'unfurl_media' => false,
        ]));

        return (string) ($r['ts'] ?? '');
    }

    /** Slack wants &, < and > escaped in message text. */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    private function call(string $method, array $payload, ?string $token = null): array
    {
        $token ??= $this->token();
        if (! $token) {
            throw new RuntimeException('Slack isn\'t connected yet. An admin can connect it in Admin > Slack.');
        }

        try {
            // Posting takes JSON (for the message blocks); Slack's read methods want a plain form.
            $request = Http::withToken($token)->acceptJson()->timeout(10);
            $response = $method === 'chat.postMessage'
                ? $request->asJson()->post(self::API.$method, $payload)
                : $request->asForm()->post(self::API.$method, $payload);
        } catch (\Throwable $e) {
            throw new RuntimeException('Couldn\'t reach Slack ('.$e->getMessage().').');
        }

        $body = $response->json() ?? [];
        if ($response->status() === 429) {
            throw new RuntimeException('Slack asked us to slow down. It will be retried in a few minutes.');
        }
        if (! $response->successful() || ! ($body['ok'] ?? false)) {
            throw new RuntimeException(self::explain($body['error'] ?? 'http_'.$response->status(), $body));
        }

        return $body;
    }

    private static function explain(string $error, array $body): string
    {
        return match ($error) {
            'not_in_channel' => 'The Slack app isn\'t in that channel. In Slack, open the channel and type /invite @ and the app\'s name.',
            'channel_not_found' => 'Slack can\'t find that channel. If it\'s private, invite the app to it first (/invite @ and the app\'s name); if it was deleted or renamed, pick it again.',
            'is_archived' => 'That Slack channel is archived. Pick another channel for this form.',
            'invalid_auth', 'not_authed', 'token_revoked', 'account_inactive', 'token_expired' => 'Slack rejected the bot token ('.$error.'). Paste a new one in Admin > Slack.',
            'missing_scope' => 'The Slack app is missing the "'.($body['needed'] ?? 'needed').'" permission. Add it under OAuth & Permissions, reinstall the app, and paste the token again.',
            'msg_too_long' => 'The message was too long for Slack.',
            'ratelimited' => 'Slack asked us to slow down. It will be retried in a few minutes.',
            default => 'Slack said: '.$error.'.',
        };
    }
}
