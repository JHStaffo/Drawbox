<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to RemoteLock (connect.remotelock.com) through its API. The client
 * ID and secret come from an OAuth application made in RemoteLock's
 * developer portal; Pulse signs in with them (client credentials), which
 * reaches the account the application belongs to. Both are stored
 * encrypted with the app key.
 */
class RemoteLock
{
    private const AUTH = 'https://connect.remotelock.com/oauth/token';

    private const API = 'https://api.remotelock.com';

    private const ACCEPT = 'application/vnd.lockstate+json; version=1';

    /** @return array{id: string, secret: string}|null */
    public function credentials(): ?array
    {
        $stored = Setting::get('remotelock_credentials');
        if ($stored === '') {
            return null;
        }
        try {
            $value = json_decode(Crypt::decryptString($stored), true);
        } catch (\Throwable) {
            return null; // The app key changed; they have to be pasted again.
        }

        return is_array($value) && ! empty($value['id']) && ! empty($value['secret']) ? $value : null;
    }

    public function connected(): bool
    {
        return $this->credentials() !== null;
    }

    public function saveCredentials(?string $id, ?string $secret): void
    {
        Setting::put('remotelock_credentials', $id && $secret ? Crypt::encryptString(json_encode(['id' => $id, 'secret' => $secret])) : '');
        $this->forget();
        Cache::forget('remotelock_token');
    }

    /** "8f3a…91bc", safe to show on the page. */
    public function maskedId(): ?string
    {
        $id = $this->credentials()['id'] ?? null;

        return $id ? substr($id, 0, 4).'…'.substr($id, -4) : null;
    }

    /** Signs in with a client ID and secret, to check them before they are saved. */
    public function check(string $id, string $secret): void
    {
        $this->requestToken($id, $secret);
    }

    /** Drops the cached lists so the next page load reads RemoteLock again. */
    public function forget(): void
    {
        Cache::forget('remotelock_users');
        Cache::forget('remotelock_door_groups');
    }

    /**
     * Every access user (not guests), current, upcoming and deactivated,
     * as id => [id, name, email, status]. Cached for 10 minutes.
     *
     * @return array<string, array{id: string, name: string, email: string, status: string}>
     */
    public function accessUsers(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('remotelock_users');
        }

        return Cache::remember('remotelock_users', 600, function () {
            $users = [];
            foreach ($this->all('/access_persons', 'type[]=access_user&attributes[status][]=current&attributes[status][]=upcoming&attributes[status][]=deactivated') as $row) {
                if (($row['type'] ?? 'access_user') !== 'access_user') {
                    continue;
                }
                $users[$row['id']] = self::person($row);
            }
            uasort($users, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

            return $users;
        });
    }

    /**
     * Door groups as id => name, A to Z. Cached for 10 minutes.
     *
     * @return array<string, string>
     */
    public function doorGroups(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('remotelock_door_groups');
        }

        return Cache::remember('remotelock_door_groups', 600, function () {
            $groups = [];
            foreach ($this->all('/groups') as $row) {
                if (($row['type'] ?? '') === 'door_group') {
                    $groups[$row['id']] = (string) ($row['attributes']['name'] ?? $row['id']);
                }
            }
            asort($groups, SORT_NATURAL | SORT_FLAG_CASE);

            return $groups;
        });
    }

    /** @return array{id: string, name: string, email: string, status: string}|null  null when RemoteLock has no such person */
    public function find(string $id): ?array
    {
        $r = $this->call('get', '/access_persons/'.rawurlencode($id), [], allowMissing: true);

        return $r ? self::person($r['data']) : null;
    }

    /** Adds an access user with a PIN RemoteLock makes up. */
    public function create(string $name, ?string $email): array
    {
        $r = $this->call('post', '/access_persons', [
            'type' => 'access_user',
            'attributes' => array_filter(['name' => $name, 'email' => $email, 'generate_pin' => true]),
        ]);
        $this->forget();

        return self::person($r['data']);
    }

    public function update(string $id, string $name, ?string $email): void
    {
        $this->call('put', '/access_persons/'.rawurlencode($id), ['attributes' => ['name' => $name, 'email' => $email]]);
        $this->forget();
    }

    /** Turns their access off on every door. RemoteLock keeps the record and its history. */
    public function deactivate(string $id): void
    {
        $this->call('put', '/access_persons/'.rawurlencode($id).'/deactivate');
        $this->forget();
    }

    /** @return list<string> the ids of the door groups, locks and locations they can open */
    public function accessIds(string $id): array
    {
        return collect($this->all('/access_persons/'.rawurlencode($id).'/accesses'))
            ->map(fn ($row) => (string) ($row['attributes']['accessible_id'] ?? ''))
            ->filter()->values()->all();
    }

    public function grantDoorGroup(string $personId, string $groupId): void
    {
        $this->call('post', '/access_persons/'.rawurlencode($personId).'/accesses', [
            'attributes' => ['accessible_type' => 'door_group', 'accessible_id' => $groupId],
        ]);
    }

    private static function person(array $row): array
    {
        $a = $row['attributes'] ?? [];

        return [
            'id' => (string) $row['id'],
            'name' => trim((string) ($a['name'] ?? '')),
            'email' => strtolower(trim((string) ($a['email'] ?? ''))),
            'status' => (string) ($a['status'] ?? ''),
        ];
    }

    /** Every page of a list (RemoteLock gives at most 50 a page). */
    private function all(string $path, string $query = ''): array
    {
        $rows = [];
        $page = 1;
        do {
            $r = $this->call('get', $path.'?'.ltrim($query.'&per_page=50&page='.$page, '&'));
            $rows = [...$rows, ...($r['data'] ?? [])];
            $pages = (int) ($r['meta']['total_pages'] ?? 1);
            $page++;
        } while ($page <= $pages && $page <= 200);

        return $rows;
    }

    private function token(): string
    {
        $credentials = $this->credentials();
        if (! $credentials) {
            throw new RuntimeException('RemoteLock isn\'t connected. An admin can connect it in Admin > RemoteLock.');
        }
        $cached = Cache::get('remotelock_token');
        if ($cached) {
            return $cached;
        }
        [$token, $seconds] = $this->requestToken($credentials['id'], $credentials['secret']);
        Cache::put('remotelock_token', $token, max(60, $seconds - 120));

        return $token;
    }

    /** @return array{0: string, 1: int} token and seconds until it expires */
    private function requestToken(string $id, string $secret): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->post(self::AUTH, [
                'grant_type' => 'client_credentials',
                'client_id' => $id,
                'client_secret' => $secret,
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Couldn\'t reach RemoteLock ('.$e->getMessage().').');
        }
        if (in_array($response->status(), [400, 401], true)) {
            throw new RuntimeException('RemoteLock turned down the client ID and secret. Copy them again from the RemoteLock developer portal.');
        }
        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException('RemoteLock sign-in failed (error '.$response->status().').');
        }

        return [(string) $response->json('access_token'), (int) ($response->json('expires_in') ?: 7200)];
    }

    private function call(string $method, string $path, array $body = [], bool $allowMissing = false): ?array
    {
        $send = function () use ($method, $path, $body) {
            $request = Http::withToken($this->token())->withHeaders(['Accept' => self::ACCEPT])->timeout(20);

            return $method === 'get' ? $request->get(self::API.$path) : $request->asJson()->{$method}(self::API.$path, $body);
        };

        try {
            $response = $send();
            if ($response->status() === 401) {
                // The saved token ran out early; sign in again once.
                Cache::forget('remotelock_token');
                $response = $send();
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Couldn\'t reach RemoteLock ('.$e->getMessage().').');
        }

        if ($allowMissing && $response->status() === 404) {
            return null;
        }
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $json = $response->json() ?? [];
        $detail = collect($json['errors'] ?? [])->flatMap(fn ($e) => (array) ($e['full_messages'] ?? $e['messages'] ?? []))->implode(' ')
            ?: ($json['message'] ?? '');

        throw new RuntimeException(match ($response->status()) {
            401 => 'RemoteLock turned down the saved client ID and secret. Connect it again in Admin > RemoteLock.',
            403 => 'RemoteLock says this account isn\'t allowed to do that.'.($detail ? " ({$detail})" : ''),
            404 => 'RemoteLock couldn\'t find that record.',
            429 => 'RemoteLock asked us to slow down. It will be retried in a few minutes.',
            default => 'RemoteLock said: '.($detail ?: 'error '.$response->status()).'.',
        });
    }
}
