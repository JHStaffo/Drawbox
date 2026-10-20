<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request, Inventory $inventory)
    {
        $user = $request->user();

        return view('home', [
            'credits' => $user->credits()->available()->with('call')->orderBy('expires_at')->get(),
            'pending' => $user->draws()->where('status', 'pending_brand')->get(),
            'history' => $user->draws()->whereIn('status', ['won', 'awarded'])->latest('drawn_at')->limit(25)->get(),
            'boxValue' => $inventory->boxValueCents(),
            'yearTotal' => $user->draws()->where('status', 'awarded')->whereYear('awarded_at', now()->year)->sum('value_cents'),
        ]);
    }
}
