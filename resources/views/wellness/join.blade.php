@extends('layouts.app', ['title' => 'Sign up for Wellness'])

@section('band')
    <h1>Sign up for Wellness</h1>
    <p>Wellness is optional. Read the agreement below, and the rest of Wellness opens once you agree.</p>
@endsection

@section('content')
<form class="card agree-card" method="post" action="{{ route('wellness.join') }}">@csrf
    <h2>Wellness program agreement</h2>
    <p class="hint">Rules dated {{ $version }}@if ($participant) · You left the program on {{ $participant->withdrawn_at?->format('F j, Y') }}. Your earlier points are still yours. @endif</p>

    <h3>What the program is</h3>
    <ul class="steps">
        <li>You earn points for everyday healthy habits: up to {{ \App\Wellness\Scoring::dailyMax() }} a day for tracking meals, sleep, a workout, steps, water and your meal plan.</li>
        <li>Earning all {{ \App\Wellness\Scoring::weeklyTarget() }} daily points in a Sunday to Saturday week earns a $10 gift card of your choice. Check-ups, dentist and eye visits, self-care visits, weigh-ins and InBody improvement add points toward the monthly, quarterly and annual prizes.</li>
        <li>To qualify for rewards you need an approved wearable (Apple Watch or Fitbit) connected, unless an administrator approves an exception.</li>
        <li>The company isn't prescribing a diet or telling you what's healthy for you. You follow your own meal plan and confirm it yourself.</li>
        <li>Points before {{ \App\Wellness\Calendar::officialStart()->format('F j, Y') }} are a test period and don't count toward prizes.</li>
    </ul>

    <h3>Your information</h3>
    <ul class="steps">
        <li><strong>Everyone in the program sees</strong> your name, your point totals and your rank on the leaderboard.</li>
        <li><strong>Only you and the wellness administrators see</strong> your steps, sleep, workouts, weight, InBody results, visits and any notes you upload.</li>
        <li>For a visit, you only ever upload a simple signed attendance note. We never ask for a diagnosis, treatment or test results.</li>
        <li>Wellness information isn't shared with other parts of Fast Track Pulse.</li>
        <li>Every point change, approval and prize decision is recorded, so mistakes can be checked and fixed.</li>
        <li>You can leave the program at any time from My Wellness. Points you've earned are kept.</li>
    </ul>
    <p><a href="{{ route('wellness.rules') }}">Read the full point rules</a></p>

    <div class="field mt">
        <label class="check"><input type="checkbox" name="consent" value="1" required> I have read this agreement and the point rules, and I agree to take part.</label>
    </div>
    <button class="btn btn-big" type="submit">I agree</button>
</form>
@endsection
