@extends('layouts.app', ['title' => 'Wellness rules'])

@section('band')
    <h1>Rules and privacy</h1>
    <p>Rules dated {{ $version }}. Official scoring starts {{ $officialStart->format('F j, Y') }}.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <h2>Daily habits</h2>
        <p>Up to {{ \App\Wellness\Scoring::dailyMax() }} points a day and {{ \App\Wellness\Scoring::weeklyTarget() }} a week (Sunday to Saturday). Earn all {{ \App\Wellness\Scoring::weeklyTarget() }} in a week for a $10 gift card of your choice. Other activities can't make up for missing daily-habit points.</p>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Habit</th><th>What counts</th><th class="num">Points</th><th class="num">Most a day</th></tr></thead>
            <tbody>
            @foreach ($rules->filter->isDaily() as $rule)
                <tr><td>{{ $rule->name }}</td><td>{{ $rule->description }}</td><td class="num">{{ $rule->points }}{{ $rule->per_unit ? ' each' : '' }}</td><td class="num">{{ $rule->dailyMax() }}</td></tr>
            @endforeach
            </tbody>
        </table>
        </div>
    </div>
    <div class="card">
        <h2>Other wellness activities</h2>
        <p>These count toward the monthly, quarterly and annual contests. Visits need a simple signed attendance note, like a school excuse note. We never ask for a diagnosis, treatment or test results.</p>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Activity</th><th>What counts</th><th class="num">Points</th><th class="num">Limit</th></tr></thead>
            <tbody>
            @foreach ($rules->reject->isDaily() as $rule)
                <tr><td>{{ $rule->name }}</td><td>{{ $rule->description }}</td><td class="num">{{ $rule->points }}</td><td class="num">{{ $rule->capLabel() }}</td></tr>
            @endforeach
            </tbody>
        </table>
        </div>
    </div>
    <div class="grid grid-2">
        <div class="card">
            <h2>How days are counted</h2>
            <ul class="steps">
                <li>A day is a calendar day, Eastern time. Sleep counts toward the day you wake up.</li>
                <li>If the same thing comes from more than one place (your watch and a typed entry, say), the higher value counts once. It is never added together.</li>
                <li>You can fill in or fix a day until {{ $grace }} days after its week ends. After that the week is closed, and only a wellness administrator can make a change, with a written reason.</li>
                <li>Points you've earned stay yours if your device disconnects or you replace it.</li>
                @if ($manualDevice)<li>For now, everyone can type in steps, sleep and workouts while wearable syncing is being set up.</li>@endif
            </ul>
        </div>
        <div class="card">
            <h2>Privacy</h2>
            <ul class="steps">
                <li>The leaderboard shows only names, points and ranks.</li>
                <li>Your sleep, steps, weight, InBody results, visits and notes are visible only to you and the wellness administrators who run the program.</li>
                <li>Nothing from Wellness is shared with other parts of Fast Track Pulse.</li>
                <li>We collect only what's needed to score points and award prizes. Every change to points, approvals and prize decisions is recorded.</li>
                <li>You can leave the program anytime from My Wellness.</li>
            </ul>
        </div>
    </div>
</div>
@endsection
