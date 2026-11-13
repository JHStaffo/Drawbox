@extends('layouts.app', ['title' => 'Wellness devices'])

@section('band')
    <h1>Devices</h1>
    <p>Connect your wearable so steps, sleep and workouts fill in on their own.</p>
@endsection

@section('content')
<div class="stack">
    @foreach ($stale as $s)
        <div class="flash flash-error">{{ $s['source_label'] }} hasn't synced since {{ \Carbon\Carbon::parse($s['at'])->format('l, M j \a\t g:i A') }}. Run the Fast Track Pulse shortcut once by hand to see what's wrong, and check the tips below.</div>
    @endforeach

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
            <h3>Set it up once (about 5 minutes)</h3>
            <ol class="steps">
                <li>
                    Make a sync key, then copy it.
                    <form method="post" action="{{ route('wellness.connect.store') }}" class="row-actions">@csrf
                        <input type="text" name="name" placeholder="Apple Health Shortcut" maxlength="60" style="max-width:260px">
                        <button class="btn btn-small" type="submit">Make a sync key</button>
                    </form>
                </li>
                <li>
                    On your iPhone, open this page and tap
                    @if ($shortcutUrl)
                        <a class="btn btn-small" href="{{ $shortcutUrl }}" target="_blank" rel="noopener">Get the Fast Track Pulse shortcut</a>,
                    @else
                        <strong>Get the Fast Track Pulse shortcut</strong> (the link appears here once it's ready),
                    @endif
                    then tap Add Shortcut. Paste your sync key when it asks.
                </li>
                <li>Run the shortcut once. Tap Allow when it asks to read Steps, Sleep and Exercise Minutes, and Allow when it asks to connect to {{ parse_url($syncUrl, PHP_URL_HOST) }}. It shows a message with what it sent.</li>
                <li>In Shortcuts, tap Automation, then New Automation, then Time of Day. Pick 9:00 PM, Daily, and Run Immediately. Choose the Fast Track Pulse shortcut.</li>
            </ol>
            <p class="hint">Each night it sends that day's totals and the day before, so a night your phone missed is caught up the next night. Steps from your iPhone and Apple Watch aren't counted twice.</p>
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
        <h2>If your data isn't showing up</h2>
        <ul class="steps">
            <li><strong>Nothing synced last night:</strong> make sure the automation in Shortcuts is on and set to Run Immediately. Your phone has to be on, but it can be locked.</li>
            <li><strong>"Your key doesn't work" or "Unauthenticated":</strong> make a new sync key here, then open the shortcut, tap the first Text box and paste the new key.</li>
            <li><strong>Steps look low:</strong> open the Health app, tap Steps, then Data Sources & Access, and make sure your Apple Watch is on the list.</li>
            <li><strong>No sleep:</strong> turn on Sleep in the Health app (or Sleep Focus) and wear your watch to bed. Sleep counts toward the day you wake up.</li>
            <li>If we don't hear from your device for 2 days, we'll email you a reminder.</li>
        </ul>
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
