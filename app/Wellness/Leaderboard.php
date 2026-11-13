<?php

namespace App\Wellness;

use App\Wellness\Models\Participant;
use App\Wellness\Models\Point;

/**
 * Named standings. Only names, points and ranks: never weights, sleep,
 * visits or anything else about someone's health.
 */
class Leaderboard
{
    public const PERIODS = ['week' => 'This week', 'month' => 'This month', 'quarter' => 'This quarter', 'year' => 'This year'];

    /**
     * @return array{period: string, label: string, from: string, to: string, test_period: bool, rows: array<int, array{rank: int, user_id: int, name: string, points: int}>}
     */
    public function standings(string $period, ?string $day = null): array
    {
        [$from, $to] = Calendar::period($period, $day);
        $participants = Participant::with('user')->where('status', 'active')->get()
            ->filter(fn (Participant $p) => $p->user?->active);
        $totals = Point::whereIn('user_id', $participants->pluck('user_id'))
            ->where('day', '>=', $from->toDateString())->where('day', '<=', $to->toDateString())
            ->selectRaw('user_id, SUM(points) as total')->groupBy('user_id')->pluck('total', 'user_id');

        $rows = $participants->map(fn (Participant $p) => [
            'user_id' => $p->user_id,
            'name' => $p->user->name,
            'points' => (int) ($totals[$p->user_id] ?? 0),
        ])->sortBy([['points', 'desc'], ['name', 'asc']])->values();

        // Equal points share a rank for now; contest tie-breakers decide prizes.
        $rank = 0;
        $last = null;
        $rows = $rows->map(function ($row, $i) use (&$rank, &$last) {
            if ($row['points'] !== $last) {
                $rank = $i + 1;
                $last = $row['points'];
            }

            return ['rank' => $rank] + $row;
        })->all();

        return [
            'period' => $period,
            'label' => Calendar::periodLabel($period, $day),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'test_period' => Calendar::isTestDay($from),
            'rows' => $rows,
        ];
    }
}
