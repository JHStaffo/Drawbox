<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\Draw;
use App\Models\DrawCredit;
use App\Services\Inventory;
use App\Services\Reports;

class DashboardController extends Controller
{
    public function index(Reports $reports, Inventory $inventory)
    {
        return view('supervisor.dashboard', [
            'undrawn' => DrawCredit::available()->with('user', 'call')->orderBy('expires_at')->get(),
            'unawarded' => Draw::whereIn('status', ['won', 'pending_brand'])->with('user')->orderBy('drawn_at')->get(),
            'periods' => collect($reports->periods())->map(fn ($range) => $reports->awardedSummary(...$range)),
            'odds' => $inventory->odds(),
            'boxValue' => $inventory->boxValueCents(),
        ]);
    }
}
