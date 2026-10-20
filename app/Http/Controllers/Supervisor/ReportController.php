<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\Draw;
use App\Models\User;
use App\Services\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request, Reports $reports)
    {
        [$from, $to] = $this->range($request);

        return view('supervisor.reports', [
            'from' => $from,
            'to' => $to,
            'filters' => $request->only(['user_id', 'status']),
            'employees' => User::orderBy('name')->get(),
            'draws' => $this->query($request, $from, $to)->get(),
            'totals' => $reports->employeeTotals($from, $to),
        ]);
    }

    public function prizesCsv(Request $request)
    {
        [$from, $to] = $this->range($request);
        $rows = $this->query($request, $from, $to)->get()->map(fn (Draw $d) => [
            $d->drawn_at->format('Y-m-d H:i'), $d->user->name, $d->user->employee_number,
            $d->label(), number_format($d->value_cents / 100, 2), $d->pto_hours,
            $d->taxable ? 'yes' : 'no', $d->status,
            $d->awarded_at?->format('Y-m-d H:i'), $d->awarder?->name,
            $d->credit->call->location, $d->credit->call->occurred_at->format('Y-m-d H:i'),
        ]);

        return $this->csv("prizes-{$from->toDateString()}-to-{$to->toDateString()}.csv", [
            'Drawn', 'Employee', 'Employee #', 'Prize', 'Value', 'PTO hours', 'Taxable',
            'Status', 'Received', 'Handed out by', 'Call location', 'Call time',
        ], $rows);
    }

    public function employeesCsv(Request $request, Reports $reports)
    {
        [$from, $to] = $this->range($request);
        $rows = $reports->employeeTotals($from, $to)->map(fn ($r) => [
            $r['user']->name, $r['user']->employee_number, $r['user']->email, $r['count'],
            number_format($r['taxable_cents'] / 100, 2), number_format($r['nontaxable_cents'] / 100, 2), $r['pto_hours'],
        ]);

        return $this->csv("employee-totals-{$from->toDateString()}-to-{$to->toDateString()}.csv", [
            'Employee', 'Employee #', 'Email', 'Prizes received', 'Taxable value', 'Non-taxable value', 'PTO hours',
        ], $rows);
    }

    private function range(Request $request): array
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $from = $request->filled('from') ? CarbonImmutable::parse($request->from) : CarbonImmutable::now()->startOfYear();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->to) : CarbonImmutable::now();

        return [$from->startOfDay(), $to->endOfDay()];
    }

    /** Prizes drawn in the range, or received in it when filtering to received. */
    private function query(Request $request, CarbonImmutable $from, CarbonImmutable $to)
    {
        $status = $request->input('status');
        $dateColumn = $status === 'awarded' ? 'awarded_at' : 'drawn_at';

        return Draw::with('user', 'awarder', 'credit.call')
            ->whereBetween($dateColumn, [$from, $to])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->input('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->orderByDesc($dateColumn);
    }

    private function csv(string $filename, array $header, $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
