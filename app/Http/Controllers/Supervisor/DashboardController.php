<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\Draw;
use App\Models\DrawCredit;
use App\Models\LockerAssignment;
use App\Services\Inventory;
use App\Services\Reports;

class DashboardController extends Controller
{
    public function index(Reports $reports, Inventory $inventory)
    {
        return view('supervisor.dashboard', [
            'undrawn' => DrawCredit::available()->with('user', 'call')->orderBy('expires_at')->get(),
            'unawarded' => Draw::whereIn('status', ['won', 'pending_brand'])->with('user')->orderBy('drawn_at')->get()
                ->sortBy(fn ($draw) => $draw->user->sortName())->values(),
            'lockerAssignments' => LockerAssignment::with('locker', 'user', 'draws')->whereNull('picked_up_at')->whereNull('cleared_at')->orderBy('due_at')->get(),
            'periods' => collect($reports->periods())->map(fn ($range) => $reports->awardedSummary(...$range)),
            'odds' => $inventory->odds(),
            'boxValue' => $inventory->boxValueCents(),
        ]);
    }
}
