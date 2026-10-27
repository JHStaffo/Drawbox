<?php

namespace App\Http\Controllers;

use App\Models\Draw;
use App\Models\DrawCredit;
use App\Services\DrawMachine;
use Illuminate\Http\Request;
use RuntimeException;

class DrawController extends Controller
{
    public function __construct(private DrawMachine $machine) {}

    public function store(Request $request, DrawCredit $credit)
    {
        abort_unless($credit->user_id === $request->user()->id, 403);

        try {
            $draw = $this->machine->draw($credit);
        } catch (RuntimeException $e) {
            return redirect()->route('home')->with('error', $e->getMessage());
        }

        audit_note("Drew {$draw->prize_name} (".money($draw->value_cents).') from the call at '.$credit->call->location.'.');

        return redirect()->route('draws.show', [$draw, 'reveal' => 1]);
    }

    public function show(Request $request, Draw $draw)
    {
        abort_unless($draw->user_id === $request->user()->id || $request->user()->hasRole('supervisor'), 403);

        return view('draws.show', [
            'draw' => $draw,
            'brands' => $draw->status === 'pending_brand' ? $this->machine->brandsFor($draw) : collect(),
            'reveal' => $request->boolean('reveal'),
        ]);
    }

    public function cashBonus(Request $request, Draw $draw)
    {
        abort_unless($draw->user_id === $request->user()->id, 403);

        try {
            $this->machine->takeCashBonus($draw);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        audit_note('Took a '.money($draw->value_cents)." cash bonus instead of {$draw->prize_name}.");

        return redirect()->route('draws.show', $draw);
    }

    public function brand(Request $request, Draw $draw)
    {
        abort_unless($draw->user_id === $request->user()->id, 403);
        $data = $request->validate(['stock_id' => 'required|integer']);

        try {
            $draw = $this->machine->chooseBrand($draw, $data['stock_id']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        audit_note("Picked {$draw->label()}.");

        return redirect()->route('draws.show', $draw);
    }
}
