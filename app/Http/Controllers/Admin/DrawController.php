<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Draw;
use App\Services\DrawMachine;
use Illuminate\Http\Request;

/** Admin corrections to a prize an employee has won. */
class DrawController extends Controller
{
    public function edit(Draw $draw)
    {
        return view('admin.draw-edit', ['draw' => $draw]);
    }

    public function update(Request $request, Draw $draw)
    {
        $data = $request->validate([
            'prize_name' => 'required|string|max:100',
            'brand' => 'nullable|string|max:100',
            'value' => 'required|numeric|min:0|max:100000',
            'pto_hours' => 'nullable|numeric|min:0|max:200',
            'taxable' => 'boolean',
        ]);

        $draw->update([
            'prize_name' => $data['prize_name'],
            'brand' => $request->has('brand') ? ($data['brand'] ?? null) ?: null : $draw->brand,
            'value_cents' => (int) round($data['value'] * 100),
            'pto_hours' => ($data['pto_hours'] ?? null) ?: null,
            'taxable' => $request->boolean('taxable'),
        ]);

        return redirect()->route('supervisor.dashboard')->with('status', "Saved {$draw->user->name}'s prize: {$draw->label()}.");
    }

    public function destroy(Request $request, Draw $draw, DrawMachine $machine)
    {
        $label = $draw->label();
        $name = $draw->user->name;
        $machine->removeDraw($draw, $request->user());

        return redirect()->route('supervisor.dashboard')->with('status', "Removed {$label} from {$name}. It went back in the box.");
    }
}
