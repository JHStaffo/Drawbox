<div class="table-wrap">
<table>
    <thead><tr><th>Habit</th><th>Goal</th><th>Logged</th><th class="num">Points</th></tr></thead>
    <tbody>
    @foreach ($day['habits'] as $h)
        <tr>
            <td>{{ $h['name'] }}</td>
            <td class="hint">{{ $h['key'] === 'meals' ? '1 per meal, up to 3' : ($h['key'] === 'meal_plan' ? 'Followed it' : $h['goal']) }}</td>
            <td>{{ $h['value'] === null ? '–' : $h['value_label'] }} @if ($h['source'] && $h['source'] !== 'manual')<span class="pill pill-teal">{{ $h['source_label'] }}</span>@endif</td>
            <td class="num">@if ($h['points'] >= $h['max'])<span class="pill pill-green">{{ $h['points'] }}/{{ $h['max'] }}</span>@else{{ $h['points'] }}/{{ $h['max'] }}@endif</td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
