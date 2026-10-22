<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Draw;
use App\Models\GiftCardStock;
use App\Models\Prize;
use App\Services\DrawMachine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Admin corrections to a prize an employee has won. */
class DrawController extends Controller
{
    public function edit(Draw $draw)
    {
        // Active prizes, plus the draw's own prize and brand even if they've since been hidden.
        $prizes = Prize::withTrashed()
            ->with(['stocks' => fn ($q) => $q->orderBy('brand')])
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('active', true)->whereNull('deleted_at'))->orWhere('id', $draw->prize_id))
            ->orderBy('type')->orderBy('value_cents')->orderBy('name')
            ->get()
            ->map(fn (Prize $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'gift_card' => $p->hasChoices(),
                'noun' => $p->choiceNoun(),
                'value' => $p->value_cents / 100,
                'pto_hours' => $p->pto_hours === null ? '' : $p->pto_hours + 0,
                'taxable' => $p->taxable,
                'brands' => $p->stocks
                    ->filter(fn ($s) => $s->active || $s->id === $draw->gift_card_stock_id)
                    ->map(fn ($s) => ['id' => $s->id, 'brand' => $s->brand, 'left' => $s->quantity])
                    ->values(),
            ]);

        // Go back to the page the admin came from (dashboard or a report) after saving.
        $from = (string) request()->headers->get('referer');
        if (str_starts_with($from, url('/').'/') && $from !== url()->current()) {
            session(['draw_edit_return' => $from]);
        } elseif ($from !== url()->current()) {
            session()->forget('draw_edit_return');
        }

        return view('admin.draw-edit', ['draw' => $draw, 'prizes' => $prizes, 'types' => Prize::TYPES]);
    }

    public function update(Request $request, Draw $draw, DrawMachine $machine)
    {
        $data = $request->validate([
            'prize_id' => ['required', Rule::exists('prizes', 'id')],
            'gift_card_stock_id' => 'nullable|integer',
            'value' => 'required|numeric|min:0|max:100000',
            'pto_hours' => 'nullable|numeric|min:0|max:200',
            'taxable' => 'boolean',
        ]);

        $prize = Prize::withTrashed()->findOrFail($data['prize_id']);
        $stock = null;
        if ($prize->hasChoices() && ! $draw->cash_bonus) {
            $stock = GiftCardStock::where('prize_id', $prize->id)->find($data['gift_card_stock_id'] ?? 0);
            if (! $stock && $draw->status === 'awarded') {
                throw ValidationException::withMessages(['gift_card_stock_id' => "Pick the {$prize->choiceNoun()} that was handed out."]);
            }
        }

        $draw = $machine->changePrize($draw, $prize, $stock, $request->user(), [
            'value_cents' => (int) round($data['value'] * 100),
            'pto_hours' => ($data['pto_hours'] ?? null) ?: null,
            'taxable' => $request->boolean('taxable'),
        ]);

        return redirect(session()->pull('draw_edit_return', route('supervisor.dashboard')))->with('status', "Saved {$draw->user->name}'s prize: {$draw->label()}.");
    }

    public function destroy(Request $request, Draw $draw, DrawMachine $machine)
    {
        $label = $draw->label();
        $name = $draw->user->name;
        $handedOut = $draw->status === 'awarded';
        $machine->removeDraw($draw, $request->user());

        return back()->with('status', "Removed {$label} from {$name}.".($handedOut ? '' : ' It went back in the box.'));
    }
}
