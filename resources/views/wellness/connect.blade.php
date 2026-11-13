@extends('layouts.app', ['title' => 'Wellness devices'])

@section('band')
    <h1>Devices</h1>
    <p>Connect your wearable so steps, sleep and workouts fill in on their own.</p>
@endsection

@section('content')
<div class="stack">
    @if ($newToken)
        <div class="card locker-banner">
            <h2>Your new sync key</h2>
            <p>Copy this now and paste it into the Apple Health Shortcut when it asks. It is shown only once.</p>
            <input type="text" readonly value="{{ $newToken }}" onclick="this.select()" class="token-input">
        </div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <h2>Apple Watch / Apple Health</h2>
            <p>An Apple Shortcut on your iPhone sends each day's steps, sleep and exercise minutes from Apple Health to Pulse. Apple doesn't let websites read Apple Health directly, so this goes through your phone.</p>
            <ol class="steps">
                <li>Make a sync key below.</li>
                <li>Install the Fast Track Pulse Shortcut on your iPhone and paste in the key. (The Shortcut and step-by-step instructions are coming soon.)</li>
                <li>Allow it to read Steps, Sleep and Exercise Minutes when it asks.</li>
            </ol>
            <form method="post" action="{{ route('wellness.connect.store') }}" class="row-actions">@csrf
                <input type="text" name="name" placeholder="Apple Health Shortcut" maxlength="60" style="max-width:260px">
                <button class="btn btn-small" type="submit">Make a sync key</button>
            </form>
        </div>
        <div class="card">
            <h2>Fitbit</h2>
            <p>Fitbit connects through your Google account. It's being set up and will be ready before the official start.</p>
            <h2 class="mt">Last sync</h2>
            @forelse ($lastSync as $s)
                <p>{{ $s['source_label'] }}: {{ \Carbon\Carbon::parse($s['at'])->format('M j, g:i A') }} ({{ \Carbon\Carbon::parse($s['at'])->diffForHumans() }})</p>
            @empty
                <p class="hint">Nothing has synced from a device yet.</p>
            @endforelse
        </div>
    </div>

    <div class="card">
        <h2>Your keys and signed-in apps</h2>
        @if ($tokens->isEmpty())
            <p class="empty">None yet.</p>
        @else
            <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th>Can</th><th>Made</th><th>Last used</th><th></th></tr></thead>
                <tbody>
                @foreach ($tokens as $token)
                    <tr>
                        <td>{{ $token->name }}</td>
                        <td>{{ $token->can(\App\Wellness\Http\Controllers\ConnectController::IMPORT_ABILITY) && ! $token->can('wellness') ? 'Send device data only' : 'Use the app' }}</td>
                        <td>{{ $token->created_at->format('M j, Y') }}</td>
                        <td>{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                        <td class="right">
                            <form method="post" action="{{ route('wellness.connect.destroy', $token->id) }}">@csrf @method('delete')
                                <button class="btn btn-outline btn-small" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
        <p class="hint mt">Remove a key if you lose your phone or stop using it. Anything using that key stops working right away.</p>
    </div>
</div>
@endsection
