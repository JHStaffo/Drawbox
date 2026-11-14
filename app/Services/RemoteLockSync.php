<?php

namespace App\Services;

use App\Models\PermissionProfile;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Keeps RemoteLock access users in step with Team Members in Pulse. Pulse
 * is the source of truth for name, email and whether someone is active;
 * which doors they open is still managed in RemoteLock, except that new
 * Team Members get their permission profile's door groups.
 */
class RemoteLockSync
{
    public function __construct(private RemoteLock $remoteLock) {}

    /** Whether Team Members added from now on should be added to RemoteLock too. */
    public function autoAdd(): bool
    {
        return $this->remoteLock->connected() && Setting::get('remotelock_auto_add') === '1';
    }

    /**
     * Called as a Team Member is saved: marks them for syncing when
     * something RemoteLock keeps has changed.
     */
    public function flag(User $user): void
    {
        if (! $user->exists) {
            if ($this->autoAdd() && $user->active !== false) {
                $user->remotelock_pending = true;
            }

            return;
        }
        if ($user->remotelock_id && $user->isDirty(['name', 'email', 'active']) && $this->remoteLock->connected()) {
            $user->remotelock_pending = true;
        }
    }

    /**
     * Sends any pending change for one Team Member to RemoteLock. Returns a
     * sentence for the page and audit log, or null when nothing was sent.
     * Problems are kept on the Team Member and retried by the scheduler.
     */
    public function push(User $user, ?string $pin = null): ?string
    {
        if (! $user->remotelock_pending || ! $this->remoteLock->connected()) {
            return null;
        }

        try {
            $done = $this->apply($user, $pin);
        } catch (RuntimeException $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
            $repeat = $user->remotelock_error === $error;
            if ($pin && ! $user->remotelock_id) {
                // A retry would give them a random PIN instead of the one typed, so it waits for someone to add them by hand.
                $user->forceFill(['remotelock_pending' => false, 'remotelock_error' => null])->saveQuietly();

                return "{$user->name} wasn't added to RemoteLock: ".$e->getMessage().' Add them with their PIN on Admin > RemoteLock.';
            }
            $user->forceFill(['remotelock_error' => $error])->saveQuietly();
            if ($repeat && app()->runningInConsole()) {
                return null; // Already logged; the scheduler keeps trying quietly.
            }

            return "RemoteLock wasn't updated for {$user->name} yet: ".$e->getMessage().' Pulse will try again in a few minutes.';
        }
        $user->forceFill(['remotelock_pending' => false, 'remotelock_error' => null, 'remotelock_synced_at' => now()])->saveQuietly();

        return $done;
    }

    /** Pushes every pending Team Member. @return list<string> what happened to each */
    public function pushPending(int $limit = 40): array
    {
        return User::where('remotelock_pending', true)->orderBy('id')->limit($limit)->get()
            ->map(fn (User $u) => $this->push($u))->filter()->values()->all();
    }

    private function apply(User $user, ?string $pin): ?string
    {
        // Not linked yet: a new Team Member, added to RemoteLock (or linked to the
        // access user with their email, so nobody is entered twice).
        if (! $user->remotelock_id) {
            if (! $user->active) {
                return null;
            }
            $match = collect($this->remoteLock->accessUsers(true))->first(fn ($p) => $p['email'] !== '' && $p['email'] === $user->email && $p['status'] !== 'deactivated'
                && ! User::where('remotelock_id', $p['id'])->exists());
            if ($match) {
                $this->link($user, $match);
                if ($pin) {
                    $this->remoteLock->setPin($match['id'], $pin);
                }

                return "Linked {$user->name} to the RemoteLock user {$match['name']} with the same email".($pin ? ' and set their PIN' : '').'.';
            }

            return $this->createFor($user, '', $pin);
        }

        $remote = $this->remoteLock->find($user->remotelock_id);
        if (! $remote) {
            $was = $user->remotelock_name ?: $user->remotelock_id;
            $user->forceFill(['remotelock_id' => null, 'remotelock_name' => null])->saveQuietly();

            return "{$user->name}'s RemoteLock user ({$was}) was deleted in RemoteLock, so the link was removed. Link or add them again on Admin > RemoteLock.";
        }

        if (! $user->active) {
            if ($remote['status'] === 'deactivated') {
                return null;
            }
            $this->remoteLock->deactivate($remote['id']);

            return "Deactivated {$user->name} in RemoteLock.";
        }

        // Back on in Pulse: RemoteLock can't turn a deactivated user back on, so a new one is made.
        if ($remote['status'] === 'deactivated') {
            return $this->createFor($user, "{$user->name} was turned back on, and their old RemoteLock user ({$remote['name']}) stays deactivated.");
        }

        if ($remote['name'] !== $user->name || $remote['email'] !== $user->email) {
            $this->remoteLock->update($remote['id'], $user->name, $user->email);
            $user->forceFill(['remotelock_name' => $user->name])->saveQuietly();
            $changes = [];
            if ($remote['name'] !== $user->name) {
                $changes[] = "name from {$remote['name']} to {$user->name}";
            }
            if ($remote['email'] !== $user->email) {
                $changes[] = 'email from '.($remote['email'] ?: 'none')." to {$user->email}";
            }

            return "Updated {$user->name} in RemoteLock: ".implode(', ', $changes).'.';
        }

        return null;
    }

    /** Adds them to RemoteLock with the PIN given (or one RemoteLock makes) and their profile's door groups. */
    public function createFor(User $user, string $prefix = '', ?string $pin = null): string
    {
        $person = $this->remoteLock->create($user->name, $user->email, $pin);
        $this->link($user, $person);
        $doors = $this->grantDefaults($user);

        return trim($prefix.' Added '.$user->name.' to RemoteLock with '.($pin ? 'the PIN you set' : 'a new PIN').($doors ? ' and door groups '.implode(', ', $doors) : ' (no door groups set for '.($user->profile?->label() ?? 'their profile').')').'.');
    }

    /** The rule for a PIN typed in Pulse: 4 to 8 digits. The PIN itself is never stored or logged in Pulse. */
    public const PIN_RULE = ['nullable', 'regex:/^\\d{4,8}$/'];

    public const PIN_MESSAGE = 'The PIN needs 4 to 8 digits.';

    public function link(User $user, array $person): void
    {
        $user->forceFill(['remotelock_id' => $person['id'], 'remotelock_name' => $person['name'], 'remotelock_error' => null, 'remotelock_synced_at' => now()])->saveQuietly();
    }

    /**
     * Gives them the door groups their profile lists, skipping ones they
     * already have. Never takes access away. @return list<string> names added
     */
    public function grantDefaults(User $user): array
    {
        $groups = self::doorGroupsFor($user->profile);
        if (! $groups || ! $user->remotelock_id) {
            return [];
        }
        try {
            $have = $this->remoteLock->accessIds($user->remotelock_id);
        } catch (RuntimeException) {
            $have = [];
        }
        $added = [];
        foreach ($groups as $id => $name) {
            if (! in_array((string) $id, $have, true)) {
                $this->remoteLock->grantDoorGroup($user->remotelock_id, (string) $id);
                $added[] = $name;
            }
        }

        return $added;
    }

    /** A profile's door groups plus its parent's (sub-profiles add to the parent). @return array<string, string> */
    public static function doorGroupsFor(?PermissionProfile $profile): array
    {
        if (! $profile) {
            return [];
        }

        return ($profile->parent?->remotelock_door_groups ?? []) + ($profile->remotelock_door_groups ?? []);
    }

    /**
     * For each Team Member not linked yet, the RemoteLock user who looks
     * like them: same email first, then the same name, then the same last
     * name and first initial, then a close spelling. Each RemoteLock user
     * is suggested once, to the best fit.
     *
     * @param  Collection<int, User>  $members
     * @param  array<string, array>  $remote  RemoteLock users nobody is linked to
     * @return array<int, array{id: string, why: string}> user id => suggestion
     */
    public static function suggest(Collection $members, array $remote): array
    {
        $candidates = [];
        foreach ($members as $member) {
            foreach ($remote as $person) {
                [$score, $why] = self::score($member, $person);
                if ($score > 0) {
                    $candidates[] = [$score, $member->id, $person['id'], $why];
                }
            }
        }
        usort($candidates, fn ($a, $b) => $b[0] <=> $a[0]);

        $picked = [];
        $used = [];
        foreach ($candidates as [$score, $userId, $personId, $why]) {
            if (isset($picked[$userId]) || isset($used[$personId])) {
                continue;
            }
            $picked[$userId] = ['id' => $personId, 'why' => $why];
            $used[$personId] = true;
        }

        return $picked;
    }

    /** @return array{0: int, 1: string} */
    private static function score(User $member, array $person): array
    {
        if ($person['email'] !== '' && $person['email'] === strtolower($member->email)) {
            return [100, 'same email'];
        }
        $a = self::words($member->name);
        $b = self::words($person['name']);
        if (! $a || ! $b) {
            return [0, ''];
        }
        if ($a === $b) {
            return [90, 'same name'];
        }
        $firstA = $a[0];
        $lastA = end($a);
        $firstB = $b[0];
        $lastB = end($b);
        if ($firstA === $firstB && $lastA === $lastB) {
            return [85, 'same first and last name'];
        }
        if ($lastA === $lastB && $firstA[0] === $firstB[0]) {
            return [70, 'same last name and first initial'];
        }
        similar_text(implode(' ', $a), implode(' ', $b), $percent);
        if ($percent >= 85) {
            return [(int) $percent - 30, 'similar name'];
        }

        return [0, ''];
    }

    /** "O'Brien-Smith, Jr." => ["obriensmith"] style: lowercase words, letters only. */
    private static function words(string $name): array
    {
        $name = mb_strtolower($name);
        // "Smith, John" => "John Smith"
        if (str_contains($name, ',')) {
            [$last, $first] = array_map('trim', explode(',', $name, 2));
            $name = "{$first} {$last}";
        }
        $words = preg_split('/\s+/', trim(preg_replace('/[^a-z\s]/', '', str_replace('-', '', $name))));

        return array_values(array_filter($words, fn ($w) => $w !== '' && ! in_array($w, ['jr', 'sr', 'ii', 'iii', 'iv'], true)));
    }
}
