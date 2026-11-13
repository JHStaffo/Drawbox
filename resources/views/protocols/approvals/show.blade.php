@extends('layouts.app', ['title' => 'Review '.$v->title])

@section('band')
    <h1>Review: {{ $v->fullTitle() }}</h1>
    <p>Version {{ $v->version }} · {{ $v->statusLabel() }} · Sent by {{ $v->submitter?->name ?? 'unknown' }} {{ $v->submitted_at?->format('M j, Y g:i A') }}
        @if ($live) · Replaces version {{ $live->version }} (<a href="{{ route('protocols.version', $live) }}" style="color:#fff" target="_blank">open it</a>)@endif</p>
@endsection

@section('content')
<div class="stack">
    <p><a href="{{ route('protocols.approvals') }}">← All approvals</a></p>

    @include('protocols.partials.page')

    @if ($v->status === 'submitted')
        <div class="grid grid-2">
            <form class="card" method="post" action="{{ route('protocols.approvals.approve', $v) }}">@csrf
                <h2>Approve</h2>
                <p class="hint">Approving puts this version in the Protocol Book for Team Members{{ $live ? ', replacing version '.$live->version : '' }}. Your name, the time and this version are recorded.</p>
                <label class="check"><input type="checkbox" name="confirm" value="1" @checked(old('confirm'))> I have reviewed this protocol and approve it as Medical Director.</label>
                <div class="field mt">
                    <label for="signature">Type your name to sign</label>
                    <input id="signature" type="text" name="signature" value="{{ old('signature') }}" required maxlength="120" placeholder="{{ auth()->user()->name }}">
                </div>
                <button class="btn btn-teal" type="submit">Approve and sign</button>
            </form>
            <form class="card" method="post" action="{{ route('protocols.approvals.return', $v) }}">@csrf
                <h2>Send back</h2>
                <p class="hint">The builders see your note and can change the draft and send it again.</p>
                <div class="field">
                    <label for="review_note">What needs to change</label>
                    <textarea id="review_note" name="review_note" rows="5" required maxlength="5000">{{ old('review_note') }}</textarea>
                </div>
                <button class="btn btn-outline" type="submit">Send back</button>
            </form>
        </div>
    @endif

    @if ($v->protocol->approved_version_id)
        <form class="card" method="post" action="{{ route('protocols.retire', $v->protocol) }}" onsubmit="return confirm('{{ $v->protocol->retired_at ? 'Put this protocol back in the book?' : 'Take this protocol out of the book? Its history is kept.' }}')">@csrf
            <button class="btn btn-small btn-outline" type="submit">{{ $v->protocol->retired_at ? 'Put back in the book' : 'Retire from the book' }}</button>
        </form>
    @endif
</div>
@include('protocols.partials.mermaid')
@endsection
