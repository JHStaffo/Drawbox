<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PermissionProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\RemoteLock;
use App\Services\RemoteLockSync;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Admin > RemoteLock: the connection, matching Team Members to the people
 * already in RemoteLock, and the door groups each profile's new Team
 * Members get.
 */
class RemoteLockController extends Controller
{
    public function index(Request $request, RemoteLock $remoteLock)
    {
        $connected = $remoteLock->connected();
        $remote = [];
        $groups = [];
        $problem = null;
        if ($connected) {
            try {
                $remote = $remoteLock->accessUsers($request->boolean('refresh'));
                $groups = $remoteLock->doorGroups($request->boolean('refresh'));
            } catch (RuntimeException $e) {
                $problem = $e->getMessage();
            }
        }

        $users = User::byLastName(User::with('profile.parent')->get());
        $linked = $users->whereNotNull('remotelock_id')->values();
        $linkedIds = $linked->pluck('remotelock_id')->flip();
        $free = array_filter($remote, fn ($p) => ! $linkedIds->has($p['id']));
        $unlinked = $users->whereNull('remotelock_id')->values();

        return view('admin.remotelock', [
            'connected' => $connected,
            'masked' => $remoteLock->maskedId(),
            'autoAdd' => Setting::get('remotelock_auto_add') === '1',
            'problem' => $problem,
            'remote' => $remote,
            'groups' => $groups,
            'linked' => $linked,
            'unlinkedActive' => $unlinked->where('active', true)->values(),
            'unlinkedInactive' => $unlinked->where('active', false)->values(),
            'free' => $free,
            'suggestions' => $problem ? [] : RemoteLockSync::suggest($unlinked, $free),
            'stillOn' => $problem ? collect() : $linked->filter(fn ($u) => ! $u->active && isset($remote[$u->remotelock_id]) && $remote[$u->remotelock_id]['status'] !== 'deactivated')->values(),
            'profiles' => PermissionProfile::ordered(),
            'pendingCount' => User::where('remotelock_pending', true)->count(),
        ]);
    }

    public function connect(Request $request, RemoteLock $remoteLock)
    {
        $data = $request->validate([
            'client_id' => 'required|string|max:200',
            'client_secret' => 'required|string|max:200',
        ]);
        [$id, $secret] = [trim($data['client_id']), trim($data['client_secret'])];
        try {
            $remoteLock->check($id, $secret);
        } catch (RuntimeException $e) {
            return back()->withErrors(['client_id' => $e->getMessage()]);
        }
        $had = $remoteLock->connected();
        $remoteLock->saveCredentials($id, $secret);

        return back()->with('status', ($had ? 'Replaced the RemoteLock connection' : 'Connected to RemoteLock').' (client ID '.$remoteLock->maskedId().'). Match your Team Members below.');
    }

    public function disconnect(RemoteLock $remoteLock)
    {
        $was = $remoteLock->maskedId();
        $remoteLock->saveCredentials(null, null);

        return back()->with('status', "Disconnected RemoteLock (client ID {$was}). Links to Team Members are kept, and changes wait until it's connected again.");
    }

    public function options(Request $request)
    {
        Setting::put('remotelock_auto_add', $request->boolean('auto_add') ? '1' : '0');

        return back()->with('status', $request->boolean('auto_add')
            ? 'New Team Members will be added to RemoteLock.'
            : 'New Team Members won\'t be added to RemoteLock. You can still add them from this page.');
    }

    /** Links the ticked Team Members to the RemoteLock users picked for them. */
    public function link(Request $request, RemoteLock $remoteLock)
    {
        $data = $request->validate(['match' => 'array', 'match.*' => 'nullable|string|max:64', 'pick' => 'array']);
        $picks = collect($data['match'] ?? [])->filter()->only(array_keys(array_filter($request->input('pick', []))));
        if ($picks->isEmpty()) {
            return back()->with('error', 'Tick at least one Team Member with a RemoteLock user picked.');
        }
        if ($picks->duplicates()->isNotEmpty()) {
            return back()->with('error', 'The same RemoteLock user is picked for more than one Team Member.');
        }

        try {
            $remote = $remoteLock->accessUsers();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $sync = app(RemoteLockSync::class);
        $done = [];
        $skipped = [];
        foreach ($picks as $userId => $personId) {
            $user = User::find($userId);
            $person = $remote[$personId] ?? null;
            if (! $user || $user->remotelock_id || ! $person || User::where('remotelock_id', $personId)->exists()) {
                $skipped[] = $user?->name ?? "#{$userId}";

                continue;
            }
            if (! $request->user()->canManage($user)) {
                $skipped[] = "{$user->name} (has access you don't)";

                continue;
            }
            $sync->link($user, $person);
            $done[] = "{$user->name} from not linked to {$person['name']}".($person['email'] ? " ({$person['email']})" : '').($person['status'] === 'deactivated' ? ', deactivated' : '');
        }

        $message = $done ? 'Linked '.count($done).' Team Member(s) to RemoteLock.' : 'Nobody was linked.';
        if ($skipped) {
            $message .= ' Skipped '.implode(', ', $skipped).'.';
        }
        audit_note($message.($done ? ' RemoteLock user: '.implode('; ', $done).'.' : ''));

        return back()->with($done ? 'status' : 'error', $message);
    }

    public function unlink(Request $request, User $user)
    {
        if (! $request->user()->canManage($user)) {
            return back()->with('error', "{$user->name} has access you don't have, so you can't change them.");
        }
        $was = $user->remotelock_name ?: $user->remotelock_id;
        $user->forceFill(['remotelock_id' => null, 'remotelock_name' => null, 'remotelock_pending' => false, 'remotelock_error' => null])->saveQuietly();
        audit_note("Unlinked {$user->name} from RemoteLock: RemoteLock user from {$was} to not linked. Nothing was changed in RemoteLock.");

        return back()->with('status', "Unlinked {$user->name}. Nothing was changed in RemoteLock.");
    }

    /** Adds the ticked Team Members to RemoteLock as new access users. */
    public function create(Request $request, RemoteLockSync $sync)
    {
        $data = $request->validate(['users' => 'required|array|max:25', 'users.*' => 'integer', 'pins' => 'array', 'pins.*' => RemoteLockSync::PIN_RULE], ['pins.*.regex' => RemoteLockSync::PIN_MESSAGE]);
        $done = [];
        $problems = [];
        foreach (User::with('profile.parent')->whereIn('id', $data['users'])->whereNull('remotelock_id')->get() as $user) {
            if (! $request->user()->canManage($user)) {
                $problems[] = "{$user->name} has access you don't have.";

                continue;
            }
            try {
                $done[] = $sync->createFor($user, '', $data['pins'][$user->id] ?? null);
            } catch (RuntimeException $e) {
                $problems[] = "{$user->name}: ".$e->getMessage();
            }
        }
        $message = trim(implode(' ', $done).' '.implode(' ', $problems)) ?: 'Nobody was added.';

        return back()->with($done ? 'status' : 'error', $message);
    }

    /** Gives a linked Team Member the door groups their profile lists (never removes any). */
    public function grant(Request $request, User $user, RemoteLockSync $sync)
    {
        if (! $request->user()->canManage($user)) {
            return back()->with('error', "{$user->name} has access you don't have, so you can't change them.");
        }
        if (! $user->remotelock_id) {
            return back()->with('error', "{$user->name} isn't linked to RemoteLock.");
        }
        try {
            $added = $sync->grantDefaults($user);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $added
            ? "Gave {$user->name} door groups ".implode(', ', $added).' in RemoteLock.'
            : "{$user->name} already has every door group their profile lists.");
    }

    /** Sets a linked Team Member's door PIN. The PIN is sent to RemoteLock only, never stored or logged in Pulse. */
    public function pin(Request $request, User $user, RemoteLock $remoteLock)
    {
        $request->validate(['pin' => ['required', ...array_slice(RemoteLockSync::PIN_RULE, 1)]], ['pin.regex' => RemoteLockSync::PIN_MESSAGE]);
        if (! $request->user()->canManage($user)) {
            return back()->with('error', "{$user->name} has access you don't have, so you can't change them.");
        }
        if (! $user->remotelock_id) {
            return back()->with('error', "{$user->name} isn't linked to RemoteLock.");
        }
        try {
            $remoteLock->setPin($user->remotelock_id, $request->input('pin'));
        } catch (RuntimeException $e) {
            return back()->with('error', "{$user->name}'s PIN wasn't changed. ".$e->getMessage());
        }

        return back()->with('status', "Changed {$user->name}'s door PIN in RemoteLock.");
    }

    /** Deactivates, in RemoteLock, everyone linked who is turned off in Pulse. */
    public function deactivate(Request $request, RemoteLock $remoteLock)
    {
        try {
            $remote = $remoteLock->accessUsers(true);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $done = [];
        $problems = [];
        foreach (User::where('active', false)->whereNotNull('remotelock_id')->get() as $user) {
            $person = $remote[$user->remotelock_id] ?? null;
            if (! $person || $person['status'] === 'deactivated' || ! $request->user()->canManage($user)) {
                continue;
            }
            try {
                $remoteLock->deactivate($user->remotelock_id);
                $done[] = "{$user->name} ({$person['name']}) from {$person['status']} to deactivated";
            } catch (RuntimeException $e) {
                $problems[] = "{$user->name}: ".$e->getMessage();
            }
        }
        $message = ($done ? 'Deactivated '.count($done).' in RemoteLock.' : 'Nobody needed deactivating.').($problems ? ' '.implode(' ', $problems) : '');
        audit_note($message.($done ? ' RemoteLock status: '.implode('; ', $done).'.' : ''));

        return back()->with($problems && ! $done ? 'error' : 'status', $message);
    }

    /** Saves which door groups each profile's new Team Members get. */
    public function doors(Request $request, RemoteLock $remoteLock)
    {
        $data = $request->validate(['doors' => 'array', 'doors.*' => 'array', 'doors.*.*' => 'string|max:64']);
        try {
            $groups = $remoteLock->doorGroups();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $skipped = [];
        $saved = [];
        // Main profiles first, so a sub-profile sees what its main profile has now.
        foreach (PermissionProfile::orderByRaw('parent_id is not null')->orderBy('id')->get() as $profile) {
            $picked = array_values(array_intersect($data['doors'][$profile->id] ?? [], array_keys($groups)));
            $inherited = $profile->parent_id ? ($saved[$profile->parent_id] ?? []) : [];
            $chosen = collect($picked)->reject(fn ($id) => isset($inherited[$id]))->mapWithKeys(fn ($id) => [$id => $groups[$id]])->sortKeys()->all();
            $current = collect($profile->remotelock_door_groups ?? [])->sortKeys()->all();
            if ($chosen !== $current && ! $request->user()->canEditProfile($profile)) {
                $skipped[] = $profile->label();
                $chosen = $current;
            } elseif ($chosen !== $current) {
                $profile->update(['remotelock_door_groups' => $chosen ?: null]);
            }
            $saved[$profile->id] = $chosen;
        }

        return back()->with('status', 'Saved door groups for new Team Members.'.($skipped ? ' Skipped '.implode(', ', $skipped).' (more access than you have).' : ''));
    }

    /** Sends waiting changes now instead of at the next scheduler run. */
    public function sync(RemoteLockSync $sync, RemoteLock $remoteLock)
    {
        $remoteLock->forget();
        $done = $sync->pushPending();

        return back()->with('status', $done ? implode(' ', $done) : 'Nothing was waiting to go to RemoteLock.');
    }
}
