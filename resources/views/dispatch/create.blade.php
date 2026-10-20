@extends('layouts.app', ['title' => 'Credit a call'])

@section('band')
    <h1>Credit a call</h1>
    <p>Each crew member you pick gets one draw, good for {{ $windowDays }} {{ Str::plural('day', (int) $windowDays) }}.</p>
@endsection

@section('content')
<div class="grid grid-2">
    <form class="card" method="post" action="{{ route('dispatch.store') }}">
        @csrf
        <div class="field-row">
            <div class="field">
                <label for="occurred_at">Date and time of the call</label>
                <input id="occurred_at" type="datetime-local" name="occurred_at" value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}" required>
            </div>
            <div class="field">
                <label for="unit">Unit</label>
                <input id="unit" type="text" name="unit" value="{{ old('unit') }}" placeholder="e.g. Medic 12">
            </div>
        </div>
        <div class="field">
            <label for="location">Facility or location</label>
            <input id="location" type="text" name="location" value="{{ old('location') }}" required>
        </div>
        <div class="field">
            <label>Crew on the truck</label>
            <div class="crew-list">
                @foreach ($employees as $e)
                    <label><input type="checkbox" name="crew[]" value="{{ $e->id }}" @checked(in_array($e->id, old('crew', [])))> {{ $e->name }}</label>
                @endforeach
            </div>
        </div>
        <div class="field">
            <label for="notes">Notes (optional)</label>
            <textarea id="notes" name="notes" rows="2">{{ old('notes') }}</textarea>
        </div>
        <button class="btn btn-block" type="submit">Credit the crew</button>
    </form>

    <div class="card">
        <h2>Recently credited</h2>
        @forelse ($recent as $call)
            <div style="padding:8px 0;border-bottom:1px solid var(--line)">
                <strong>{{ $call->location }}</strong>{{ $call->unit ? ' · '.$call->unit : '' }}
                <div class="hint">{{ $call->occurred_at->format('M j, g:i A') }} · {{ $call->credits->pluck('user.name')->join(', ') }}</div>
            </div>
        @empty
            <p class="empty">No calls credited yet.</p>
        @endforelse
    </div>
</div>
@endsection
