@extends('layouts.app', ['title' => 'Slack'])

@section('band')
    <h1>Slack</h1>
    <p>Pulse posts form submissions to Slack through its own Slack app. One connection covers every channel.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card" style="max-width:720px">
        <h2>Connection</h2>
        @if ($connected)
            <p>
                <span class="pill pill-green">Connected</span>
                @if ($workspace)
                    to the <strong>{{ $workspace['team'] }}</strong> workspace as <strong>{{ $workspace['bot'] }}</strong>.
                @else
                    <span class="pill pill-red">Slack can't be reached right now, or the token stopped working.</span>
                @endif
            </p>
            <p class="hint">Bot token {{ $masked }}. Paste a new one below to replace it.</p>
        @else
            <p><span class="pill pill-red">Not connected</span> Forms still save in Pulse, but nothing posts to Slack until a token is added.</p>
        @endif
        <form method="post" action="{{ route('admin.slack.update') }}">@csrf @method('put')
            <div class="field">
                <label for="token">Bot User OAuth Token</label>
                <input id="token" type="text" name="token" placeholder="xoxb-…" autocomplete="off" spellcheck="false" required>
                @error('token')<div class="error">{{ $message }}</div>@enderror
                <div class="hint">Pulse checks it with Slack before saving, and stores it encrypted.</div>
            </div>
            <div class="row-actions">
                <button class="btn" type="submit">{{ $connected ? 'Replace token' : 'Connect' }}</button>
            </div>
        </form>
        @if ($connected)
            <form class="mt" method="post" action="{{ route('admin.slack.destroy') }}" onsubmit="return confirm('Disconnect Slack? Forms will keep saving but stop posting.')">@csrf @method('delete')
                <button class="btn btn-small btn-outline" type="submit">Disconnect</button>
            </form>
        @endif
    </div>

    @if ($connected)
        <form class="card" method="post" action="{{ route('admin.slack.test') }}" style="max-width:720px">@csrf
            <h2>Send a test message</h2>
            <div class="field">
                <label for="channel">Channel</label>
                @if ($channels)
                    <select id="channel" name="channel" required>
                        <option value="">Choose…</option>
                        @foreach ($channels as $id => $name)<option value="{{ $id }}" @selected(old('channel') === $id)>#{{ $name }}</option>@endforeach
                    </select>
                    <div class="hint">Don't see a private channel? Invite the app to it in Slack, then <a href="{{ route('admin.slack', ['refresh' => 1]) }}">refresh the list</a>.</div>
                @else
                    <input id="channel" type="text" name="channel" value="{{ old('channel') }}" placeholder="C0123ABCD" required>
                    <div class="hint">Pulse couldn't list channels (the app may be missing the channels:read scope), so type the channel ID. It's at the bottom of the channel's details in Slack.</div>
                @endif
            </div>
            <button class="btn btn-teal" type="submit">Send test</button>
        </form>
    @endif

    <div class="card" style="max-width:720px">
        <h2>How to set it up</h2>
        <ol class="steps">
            <li>Go to <a href="https://api.slack.com/apps" target="_blank" rel="noopener">api.slack.com/apps</a> signed in to your Slack workspace, click <strong>Create New App</strong>, then <strong>From a manifest</strong>, and pick the workspace.</li>
            <li>Choose YAML, replace what's there with the text below, click Next, then <strong>Create</strong>.</li>
            <li>Click <strong>Install to Workspace</strong> (under Install App) and <strong>Allow</strong>. If Slack says it needs approval, a Slack workspace admin approves it.</li>
            <li>Open <strong>OAuth &amp; Permissions</strong> and copy the <strong>Bot User OAuth Token</strong> (it starts with xoxb-). Paste it above and click Connect.</li>
            <li>Public channels work right away. For a private channel, open it in Slack and type <code>/invite @Fast Track Pulse</code>.</li>
            <li>Send a test above, then pick each form's channel under <a href="{{ route('admin.forms') }}">Admin &gt; Forms</a>.</li>
        </ol>
<pre class="manifest">display_information:
  name: Fast Track Pulse
  description: Posts forms sent from Fast Track Pulse
  background_color: "#2c3e50"
features:
  bot_user:
    display_name: Fast Track Pulse
    always_online: true
oauth_config:
  scopes:
    bot:
      - chat:write
      - chat:write.public
      - channels:read
      - groups:read
settings:
  org_deploy_enabled: false
  socket_mode_enabled: false
  token_rotation_enabled: false</pre>
        <p class="hint">What the permissions are for: chat:write posts messages, chat:write.public posts to public channels without inviting the app, and channels:read and groups:read list channels so you can pick them from a dropdown.</p>
    </div>
</div>
@endsection
