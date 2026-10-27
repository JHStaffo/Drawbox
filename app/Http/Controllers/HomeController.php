<?php

namespace App\Http\Controllers;

use App\Models\Draw;
use App\Models\LockerAssignment;
use App\Models\Setting;
use App\Services\Inventory;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request, Inventory $inventory)
    {
        $user = $request->user();

        return view('home', [
            'credits' => $user->credits()->available()->with('call')->orderBy('expires_at')->get(),
            'lockers' => LockerAssignment::waiting()->where('user_id', $user->id)->with('locker', 'draws')->orderBy('due_at')->get(),
            'pending' => $user->draws()->where('status', 'pending_brand')->get(),
            'history' => $user->draws()->whereIn('status', ['won', 'in_locker', 'awarded', 'forfeited'])->with('lockerAssignment.locker')->latest('drawn_at')->limit(25)->get(),
            'boxValue' => $inventory->boxValueCents(),
            'winners' => $this->recentWinners(),
            'yearTotal' => $user->draws()->where('status', 'awarded')->whereYear('awarded_at', now()->year)->sum('value_cents'),
        ]);
    }

    /** Recent Winners, newest first, without the roles and small prizes Prize News settings hide. */
    private function recentWinners()
    {
        $hidden = collect(['dispatcher', 'supervisor', 'admin'])->filter(fn ($r) => Setting::get("news_hide_{$r}"));

        return Draw::with('user')
            ->where('value_cents', '>=', (int) Setting::get('news_min_cents'))
            ->when($hidden->isNotEmpty(), fn ($q) => $q->whereHas('user', fn ($u) => $u->whereNotIn('role', $hidden)))
            ->latest('drawn_at')->latest('id')
            ->limit(15)
            ->get();
    }
}
