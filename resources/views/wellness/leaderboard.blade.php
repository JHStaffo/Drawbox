@extends('layouts.app', ['title' => 'Wellness leaderboard'])

@section('band')
    <h1>Leaderboard</h1>
    <p>{{ $board['label'] }}@if ($board['test_period']) · Includes test-period points, which don't count toward prizes @endif</p>
@endsection

@section('content')
<div class="stack">
    <div class="card row-actions">
        @foreach (\App\Wellness\Leaderboard::PERIODS as $key => $label)
            <a href="{{ route('wellness.leaderboard', ['period' => $key]) }}" @class(['btn btn-small', 'btn-outline' => $period !== $key, 'btn-teal' => $period === $key])>{{ $label }}</a>
        @endforeach
    </div>
    <div class="card">
        @if (! $board['rows'])
            <p class="empty">Nobody has joined yet.</p>
        @else
            <div class="table-wrap">
            <table>
                <thead><tr><th class="num">Rank</th><th>Team Member</th><th class="num">Points</th></tr></thead>
                <tbody>
                @foreach ($board['rows'] as $row)
                    <tr @class(['row-me' => $row['user_id'] === $me])>
                        <td class="num">{{ $row['rank'] }}</td>
                        <td>{{ $row['name'] }}@if ($row['user_id'] === $me) <span class="pill pill-teal">You</span>@endif</td>
                        <td class="num">{{ number_format($row['points']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
        <p class="hint mt">The leaderboard only ever shows names and points. Sleep, weight, InBody results, visits and notes stay private.</p>
    </div>
</div>
@endsection
