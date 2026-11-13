@extends('layouts.app', ['title' => 'My Wellness'])

@php($today = $summary['today'])
@php($week = $summary['week'])

@section('band')
    <h1>My Wellness</h1>
    <p>{{ now()->format('l, F j') }}@if ($today['test_period']) · Test period: points before {{ \App\Wellness\Calendar::officialStart()->format('F j, Y') }} don't count toward prizes.@endif</p>
@endsection

@section('content')
<div class="stack">
    @if ($toPick)
        <div class="card locker-banner row-actions">
            <strong>You earned {{ $toPick === 1 ? 'a gift card' : $toPick.' gift cards' }}!</strong>
            <a class="btn btn-small" href="{{ route('wellness.activities') }}">Pick your card</a>
        </div>
    @endif
    <div class="grid grid-4">
        <div class="card">
            <div class="stat-label">Today</div>
            <div class="stat-value">{{ $today['points'] }} <span class="stat-sub">of {{ $today['max'] }}</span></div>
            <a class="btn btn-small mt" href="{{ route('wellness.log') }}">Log today</a>
        </div>
        <div class="card">
            <div class="stat-label">This week</div>
            <div class="stat-value">{{ $week['points'] }} <span class="stat-sub">of {{ $week['target'] }}</span></div>
            <div class="odds-bar mt"><span style="width: {{ min(100, round($week['points'] / max(1, $week['target']) * 100)) }}%"></span></div>
            <div class="stat-sub">{{ $week['qualified'] ? 'You earned this week\'s $10 gift card!' : ($week['target'] - $week['points']).' to go for the $10 gift card' }}</div>
        </div>
        <div class="card">
            <div class="stat-label">Streak</div>
            <div class="stat-value">{{ $summary['streak'] }} <span class="stat-sub">{{ $summary['streak'] === 1 ? 'day' : 'days' }}</span></div>
            <div class="stat-sub">Days in a row with at least one point</div>
        </div>
        <div class="card">
            <div class="stat-label">All points</div>
            <div class="stat-value">{{ number_format($summary['totals']['points']) }}</div>
            <div class="stat-sub">{{ $summary['totals']['perfect_days'] }} perfect {{ $summary['totals']['perfect_days'] === 1 ? 'day' : 'days' }} of {{ $today['max'] }} points</div>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h2>Today</h2>
            @include('wellness.partials.day-habits', ['day' => $today])
        </div>
        <div class="card">
            <h2>This week</h2>
            <div class="week-strip">
                @foreach ($week['days'] as $d)
                    <a href="{{ $d['future'] ? '#' : route('wellness.log', $d['day']) }}" @class(['week-day', 'week-day-full' => $d['points'] >= $today['max'], 'week-day-future' => $d['future'], 'week-day-today' => $d['day'] === $today['day']])>
                        <span class="week-day-name">{{ $d['label'] }}</span>
                        <span class="week-day-points">{{ $d['future'] ? '' : $d['points'] }}</span>
                    </a>
                @endforeach
            </div>
            <p class="hint mt">Sunday to Saturday. Tap a day to fill in anything you missed. Days stay open until {{ \App\Wellness\Calendar::graceDays() }} days after the week ends.</p>

            <h2 class="mt">Last 30 days</h2>
            @php($max = max(1, $today['max']))
            <svg class="history-chart" viewBox="0 0 300 80" role="img" aria-label="Daily points for the last 30 days">
                @foreach ($summary['history'] as $i => $h)
                    @php($height = round($h['points'] / $max * 70, 1))
                    <rect x="{{ $i * 10 + 1 }}" y="{{ 75 - $height }}" width="8" height="{{ max($height, 0.5) }}" rx="1.5" class="{{ $h['points'] >= $max ? 'bar-full' : 'bar' }}"><title>{{ \Carbon\Carbon::parse($h['day'])->format('D M j') }}: {{ $h['points'] }} points</title></rect>
                @endforeach
                <line x1="0" y1="75.5" x2="300" y2="75.5" class="axis"/>
            </svg>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h2>Devices</h2>
            @if ($summary['last_sync'])
                @foreach ($summary['last_sync'] as $s)
                    <p>{{ $s['source_label'] }}: last synced {{ \Carbon\Carbon::parse($s['at'])->diffForHumans() }}</p>
                @endforeach
            @else
                <p>No wearable has synced yet. Steps, sleep and workouts will fill in on their own once yours is set up.</p>
            @endif
            <a class="btn btn-outline btn-small" href="{{ route('wellness.connect') }}">Set up a device</a>
        </div>
        <div class="card">
            <h2>Extra points</h2>
            <p>Check-ups, dentist and eye visits, self-care visits, InBody scans and weigh-ins count toward the monthly, quarterly and annual prizes.@if ($pendingCount) You have {{ $pendingCount }} waiting for review.@endif</p>
            <a class="btn btn-outline btn-small" href="{{ route('wellness.activities') }}">Send in an activity</a>
            <h2 class="mt">Leaderboard</h2>
            <p>See how you stack up this week, month, quarter and year.</p>
            <a class="btn btn-outline btn-small" href="{{ route('wellness.leaderboard') }}">Open the leaderboard</a>
            <form method="post" action="{{ route('wellness.withdraw') }}" class="mt" onsubmit="return confirm('Leave the wellness program? Your points so far are kept.')">@csrf
                <button class="btn btn-outline btn-small" type="submit">Leave the program</button>
            </form>
        </div>
    </div>
</div>
@endsection
