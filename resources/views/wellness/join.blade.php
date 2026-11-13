@extends('layouts.app', ['title' => 'Wellness'])

@section('band')
    <h1>Wellness</h1>
    <p>Earn points for everyday healthy habits, see your progress, and compete for company-paid rewards.</p>
@endsection

@section('content')
<div class="grid grid-2">
    <div class="card">
        <h2>How it works</h2>
        <ul class="steps">
            <li>Up to <strong>{{ \App\Wellness\Scoring::dailyMax() }} points a day</strong> for daily habits: meals tracked, sleep, a workout, steps, water and your meal plan.</li>
            <li>Get all {{ \App\Wellness\Scoring::weeklyTarget() }} daily points in a Sunday to Saturday week to earn a $10 gift card of your choice.</li>
            <li>Extra points for check-ups, dentist and eye visits, self-care visits, weigh-ins and InBody improvement count toward the monthly, quarterly and annual prizes.</li>
            <li>Steps, sleep and workouts come from your Apple Watch or Fitbit once it's connected.</li>
        </ul>
        <p><a href="{{ route('wellness.rules') }}">Read the full rules and privacy notice</a></p>
    </div>
    <form class="card" method="post" action="{{ route('wellness.join') }}">@csrf
        <h2>{{ $participant ? 'Rejoin' : 'Join' }} the program</h2>
        <p>Your name and point totals appear on the leaderboard. Your sleep, weight, InBody results, visits and notes never do: only you and the wellness administrators can see them.</p>
        <div class="field">
            <label class="check"><input type="checkbox" name="consent" value="1" required> I agree to the wellness rules (dated {{ $version }}) and the privacy notice.</label>
        </div>
        <button class="btn" type="submit">{{ $participant ? 'Rejoin' : 'Join' }} Wellness</button>
    </form>
</div>
@endsection
