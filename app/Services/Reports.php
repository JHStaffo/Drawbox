<?php

namespace App\Services;

use App\Models\Draw;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class Reports
{
    /** Calendar month, quarter, half and year containing today. */
    public function periods(): array
    {
        $now = CarbonImmutable::now();
        $halfStart = $now->month <= 6 ? $now->startOfYear() : $now->startOfYear()->addMonths(6);

        return [
            'This month' => [$now->startOfMonth(), $now->endOfMonth()],
            'This quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
            'This half' => [$halfStart, $halfStart->addMonths(5)->endOfMonth()],
            'This year' => [$now->startOfYear(), $now->endOfYear()],
        ];
    }

    /** Count and dollar value of prizes handed out in a range. */
    public function awardedSummary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $q = Draw::where('status', 'awarded')->whereBetween('awarded_at', [$from, $to]);

        return [
            'count' => (clone $q)->count(),
            'value_cents' => (int) (clone $q)->sum('value_cents'),
            'pto_hours' => (float) (clone $q)->sum('pto_hours'),
        ];
    }

    /**
     * Per-employee totals of prizes received (awarded) in a range. Prizes are
     * counted when the employee physically received them.
     */
    public function employeeTotals(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Draw::with('user')
            ->where('status', 'awarded')
            ->whereBetween('awarded_at', [$from, $to])
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $draws) => [
                'user' => $draws->first()->user,
                'count' => $draws->count(),
                'taxable_cents' => $draws->where('taxable', true)->sum('value_cents'),
                'nontaxable_cents' => $draws->where('taxable', false)->sum('value_cents'),
                'pto_hours' => $draws->sum(fn ($d) => (float) $d->pto_hours),
            ])
            ->sortBy(fn ($row) => $row['user']->name)
            ->values();
    }
}
