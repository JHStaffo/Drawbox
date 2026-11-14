@extends('layouts.app', ['title' => 'RemoteLock'])

@section('band')
    <h1>RemoteLock</h1>
    <p>Team Members in Pulse are kept in step with access users in RemoteLock. Turning someone off in Pulse deactivates them in RemoteLock, and new Team Members get their profile's door groups.</p>
@endsection

@section('content')
@php($label = fn ($p) => $p['name'].($p['email'] ? ' · '.$p['email'] : '').($p['status'] === 'deactivated' ? ' (deactivated)' : ''))
<div class="stack">
    <div class="card" style="max-width:760px">
        <h2>Connection</h2>
        @if ($connected)
            <p>
                <span class="pill pill-green">Connected</span> Client ID {{ $masked }}.
                @if ($problem)<span class="pill pill-red">{{ $problem }}</span>@else {{ count($remote) }} access user(s) and {{ count($groups) }} door group(s) in RemoteLock. <a href="{{ route('admin.remotelock', ['refresh' => 1]) }}">Refresh</a>@endif
            </p>
        @else
            <p><span class="pill pill-red">Not connected</span></p>
        @endif
        <form method="post" action="{{ route('admin.remotelock.connect') }}">@csrf @method('put')
            <div class="field-row">
                <div class="field"><label for="client_id">Client ID</label><input id="client_id" type="text" name="client_id" autocomplete="off" spellcheck="false" required maxlength="200"></div>
                <div class="field"><label for="client_secret">Client secret</label><input id="client_secret" type="password" name="client_secret" autocomplete="off" required maxlength="200"></div>
            </div>
            @error('client_id')<div class="error">{{ $message }}</div>@enderror
            <div class="hint">Pulse checks them with RemoteLock before saving, and stores them encrypted.</div>
            <div class="row-actions mt">
                <button class="btn" type="submit">{{ $connected ? 'Replace' : 'Connect' }}</button>
                @if ($connected)<button class="btn btn-outline" type="submit" form="remotelock-disconnect">Disconnect</button>@endif
            </div>
        </form>
        @if ($connected)
            <form id="remotelock-disconnect" method="post" action="{{ route('admin.remotelock.disconnect') }}" onsubmit="return confirm('Disconnect RemoteLock? Changes in Pulse will wait until it is connected again.')">@csrf @method('delete')</form>
            <form class="mt" method="post" action="{{ route('admin.remotelock.options') }}">@csrf @method('put')
                <label class="check"><input type="checkbox" name="auto_add" value="1" @checked($autoAdd) onchange="this.form.submit()"> Add new Team Members to RemoteLock (with a new PIN and their profile's door groups)</label>
            </form>
            <form class="mt" method="post" action="{{ route('admin.remotelock.sync') }}">@csrf
                <span class="hint">{{ $pendingCount ? $pendingCount.' change(s) waiting. They go out every few minutes.' : 'Nothing waiting to go to RemoteLock.' }}</span>
                @if ($pendingCount)<button class="btn btn-small btn-teal" type="submit">Send now</button>@endif
            </form>
        @endif
    </div>

    @if ($connected && ! $problem)
        @if ($stillOn->isNotEmpty())
            <form class="card card-pending" method="post" action="{{ route('admin.remotelock.deactivate') }}">@csrf
                <h2>Off in Pulse, still active in RemoteLock ({{ $stillOn->count() }})</h2>
                <p>{{ $stillOn->map(fn ($u) => $u->name.' ('.$remote[$u->remotelock_id]['name'].')')->implode(', ') }}</p>
                <button class="btn" type="submit" onclick="return confirm('Deactivate these people in RemoteLock? Their door access stops right away.')">Deactivate them in RemoteLock</button>
            </form>
        @endif

        <div class="card">
            <h2>Match Team Members ({{ $unlinkedActive->count() }} not linked)</h2>
            @if ($unlinkedActive->isEmpty())
                <p class="empty">Every active Team Member is linked.</p>
            @else
                <p class="hint">Suggestions are by email first, then name. Tick <strong>Link</strong> to link someone to the RemoteLock user picked, or tick <strong>Add new</strong> to add them to RemoteLock with a new PIN and their profile's door groups. Linking changes nothing in RemoteLock.</p>
                <form id="remotelock-link" method="post" action="{{ route('admin.remotelock.link') }}">@csrf</form>
                <form id="remotelock-create" method="post" action="{{ route('admin.remotelock.create') }}">@csrf</form>
                <div class="table-wrap"><table>
                    <tr><th>Link</th><th>Team Member</th><th>Profile</th><th>RemoteLock user</th><th>Add new</th></tr>
                    @foreach ($unlinkedActive as $user)
                        @php($s = $suggestions[$user->id] ?? null)
                        <tr>
                            <td><input type="checkbox" name="pick[{{ $user->id }}]" value="1" form="remotelock-link" @checked($s) aria-label="Link {{ $user->name }}"></td>
                            <td>{{ $user->name }}<div class="hint">{{ $user->email }}</div></td>
                            <td>{{ $user->profile?->label() }}</td>
                            <td>
                                <select name="match[{{ $user->id }}]" form="remotelock-link" onchange="this.closest('tr').querySelector('input[name^=pick]').checked = !!this.value" aria-label="RemoteLock user for {{ $user->name }}">
                                    <option value="">Choose…</option>
                                    @foreach ($free as $p)<option value="{{ $p['id'] }}" @selected($s && $s['id'] === $p['id'])>{{ $label($p) }}</option>@endforeach
                                </select>
                                @if ($s)<div class="hint">Suggested: {{ $s['why'] }}</div>@endif
                            </td>
                            <td><input type="checkbox" name="users[]" value="{{ $user->id }}" form="remotelock-create" aria-label="Add {{ $user->name }} to RemoteLock"></td>
                        </tr>
                    @endforeach
                </table></div>
                <div class="row-actions mt">
                    <button class="btn" type="submit" form="remotelock-link">Link ticked</button>
                    <button class="btn btn-teal" type="submit" form="remotelock-create" onclick="return confirm('Add the ticked Team Members to RemoteLock as new access users?')">Add ticked to RemoteLock</button>
                </div>
            @endif

            @if ($unlinkedInactive->isNotEmpty())
                <details class="mt">
                    <summary>Inactive Team Members not linked ({{ $unlinkedInactive->count() }})</summary>
                    <p class="hint mt">Link them only if they're still in RemoteLock, so they can be deactivated there.</p>
                    <form method="post" action="{{ route('admin.remotelock.link') }}">@csrf
                        <div class="table-wrap"><table>
                            <tr><th>Link</th><th>Team Member</th><th>RemoteLock user</th></tr>
                            @foreach ($unlinkedInactive as $user)
                                @php($s = $suggestions[$user->id] ?? null)
                                <tr>
                                    <td><input type="checkbox" name="pick[{{ $user->id }}]" value="1" @checked($s) aria-label="Link {{ $user->name }}"></td>
                                    <td>{{ $user->name }}<div class="hint">{{ $user->email }}</div></td>
                                    <td>
                                        <select name="match[{{ $user->id }}]" onchange="this.closest('tr').querySelector('input[name^=pick]').checked = !!this.value" aria-label="RemoteLock user for {{ $user->name }}">
                                            <option value="">Choose…</option>
                                            @foreach ($free as $p)<option value="{{ $p['id'] }}" @selected($s && $s['id'] === $p['id'])>{{ $label($p) }}</option>@endforeach
                                        </select>
                                        @if ($s)<div class="hint">Suggested: {{ $s['why'] }}</div>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </table></div>
                        <button class="btn mt" type="submit">Link ticked</button>
                    </form>
                </details>
            @endif

            @php($freeOn = collect($free)->where('status', '!=', 'deactivated'))
            @if ($freeOn->isNotEmpty())
                <details class="mt">
                    <summary>In RemoteLock but not in Pulse ({{ $freeOn->count() }})</summary>
                    <p class="hint mt">Active RemoteLock users nobody is linked to. They might be vendors, or Team Members with a different name or email. Pulse leaves them alone.</p>
                    <ul class="plain-list">@foreach ($freeOn as $p)<li>{{ $label($p) }}</li>@endforeach</ul>
                </details>
            @endif
        </div>

        <div class="card">
            <h2>Linked ({{ $linked->count() }})</h2>
            @if ($linked->isEmpty())
                <p class="empty">Nobody is linked yet.</p>
            @else
                <div class="table-wrap"><table>
                    <tr><th>Team Member</th><th>RemoteLock user</th><th>Status</th><th></th></tr>
                    @foreach ($linked as $user)
                        @php($p = $remote[$user->remotelock_id] ?? null)
                        <tr>
                            <td>{{ $user->name }}@unless ($user->active) <span class="pill">Off in Pulse</span>@endunless<div class="hint">{{ $user->profile?->label() }}</div></td>
                            <td>{{ $p ? $label($p) : ($user->remotelock_name ?: $user->remotelock_id) }}</td>
                            <td>
                                @if ($user->remotelock_error)<span class="pill pill-red" title="{{ $user->remotelock_error }}">Not synced</span><div class="hint">{{ $user->remotelock_error }}</div>
                                @elseif ($user->remotelock_pending)<span class="pill pill-amber">Waiting</span>
                                @elseif (! $p)<span class="pill pill-red">Not found in RemoteLock</span>
                                @elseif ($p['status'] === 'deactivated')<span class="pill">Deactivated</span>
                                @else<span class="pill pill-green">{{ ucfirst($p['status'] ?: 'active') }}</span>@endif
                            </td>
                            <td class="row-actions">
                                @if ($user->active && \App\Services\RemoteLockSync::doorGroupsFor($user->profile))
                                    <form method="post" action="{{ route('admin.remotelock.grant', $user) }}">@csrf<button class="btn btn-small btn-outline" type="submit" title="Adds the door groups {{ $user->profile?->label() }} lists. Never removes any.">Give profile doors</button></form>
                                @endif
                                <form method="post" action="{{ route('admin.remotelock.unlink', $user) }}" onsubmit="return confirm('Unlink {{ addslashes($user->name) }}? Nothing changes in RemoteLock.')">@csrf @method('delete')<button class="btn btn-small btn-outline" type="submit">Unlink</button></form>
                            </td>
                        </tr>
                    @endforeach
                </table></div>
            @endif
        </div>

        <form class="card" method="post" action="{{ route('admin.remotelock.doors') }}">@csrf @method('put')
            <h2>Door groups for new Team Members</h2>
            <p class="hint">When a Team Member is added to RemoteLock from Pulse, they get the door groups their permission profile lists here. A sub-profile gets its main profile's door groups plus its own. Changing these doesn't change anyone already in RemoteLock; use <strong>Give profile doors</strong> above for that.</p>
            @if (! $groups)
                <p class="empty">RemoteLock has no door groups yet.</p>
            @else
                <div class="table-wrap"><table>
                    <tr><th>Profile</th><th>Door groups</th></tr>
                    @foreach ($profiles as $profile)
                        @php($inherited = $profile->parent?->remotelock_door_groups ?? [])
                        @php($own = $profile->remotelock_door_groups ?? [])
                        <tr>
                            <td>@if ($profile->parent)<span class="hint">{{ $profile->parent->name }} ›</span> @endif<strong>{{ $profile->name }}</strong></td>
                            <td>
                                @foreach ($groups as $id => $name)
                                    @if (isset($inherited[$id]))
                                        <label class="check" title="From {{ $profile->parent->name }}"><input type="checkbox" checked disabled> {{ $name }}</label>
                                    @else
                                        <label class="check"><input type="checkbox" name="doors[{{ $profile->id }}][]" value="{{ $id }}" @checked(isset($own[$id]))> {{ $name }}</label>
                                    @endif
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </table></div>
                <button class="btn mt" type="submit">Save door groups</button>
            @endif
        </form>
    @endif

    <div class="card" style="max-width:760px">
        <h2>How to connect</h2>
        <ol class="steps">
            <li>Sign in to RemoteLock as the account owner and open the <strong>Developer Portal</strong>. If you don't see it, ask RemoteLock support to turn on API access for the account.</li>
            <li>Create a new <strong>OAuth application</strong> named Fast Track Pulse. If it asks for a redirect URI, use <code>urn:ietf:wg:oauth:2.0:oob</code>.</li>
            <li>Copy the application's <strong>Client ID</strong> and <strong>Client secret</strong>, paste them above and click Connect.</li>
            <li>Match your Team Members, set door groups for each profile, then leave <strong>Add new Team Members</strong> on.</li>
        </ol>
    </div>
</div>
@endsection
