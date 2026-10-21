<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\GiftCardStock;
use App\Models\InventoryLog;
use App\Models\Prize;
use App\Services\Inventory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function __construct(private Inventory $inventory) {}

    public function index()
    {
        return view('supervisor.inventory', [
            'odds' => $this->inventory->odds(),
            'boxValue' => $this->inventory->boxValueCents(),
            'prizes' => Prize::with('stocks')->orderBy('sort')->orderBy('value_cents')->get(),
            'hiddenStocks' => GiftCardStock::with('prize')->where('active', false)->whereHas('prize', fn ($q) => $q->where('active', true))->orderBy('brand')->get(),
            'types' => Prize::TYPES,
        ]);
    }

    public function restock(Request $request)
    {
        $changed = $this->inventory->restock($request->user());

        return back()->with('status', $changed ? "Restocked {$changed} prize(s) to their monthly amounts." : 'Everything is already at or above its monthly amount.');
    }

    public function storePrize(Request $request)
    {
        $data = $this->validatePrize($request);
        $prize = Prize::create(collect($data)->except('quantity')->all() + ['sort' => Prize::max('sort') + 1]);
        if (! $prize->isGiftCard()) {
            $this->inventory->setPrizeQuantity($prize, $data['quantity'] ?? 0, $request->user(), 'Added prize');
        }

        return back()->with('status', "Added {$prize->name}.");
    }

    public function updatePrize(Request $request, Prize $prize)
    {
        $data = $this->validatePrize($request, $prize);
        $prize->update(collect($data)->except('quantity')->all());
        if (! $prize->isGiftCard() && isset($data['quantity'])) {
            $this->inventory->setPrizeQuantity($prize, $data['quantity'], $request->user(), $request->input('reason') ?: 'Manual change');
        }

        return back()->with('status', "Saved {$prize->name}.");
    }

    public function storeStock(Request $request, Prize $prize)
    {
        abort_unless($prize->isGiftCard(), 422);
        $data = $request->validate([
            'brand' => ['required', 'string', 'max:100', Rule::unique('gift_card_stocks')->where('prize_id', $prize->id)],
            'quantity' => 'required|integer|min:0|max:10000',
            'monthly_quantity' => 'required|integer|min:0|max:10000',
        ]);
        $stock = $prize->stocks()->create(['brand' => $data['brand'], 'monthly_quantity' => $data['monthly_quantity']]);
        $this->inventory->setStockQuantity($stock, $data['quantity'], $request->user(), 'Added brand');

        return back()->with('status', "Added {$data['brand']} to {$prize->name}.");
    }

    public function updateStock(Request $request, GiftCardStock $stock)
    {
        $data = $request->validate([
            'quantity' => 'required|integer|min:0|max:10000',
            'monthly_quantity' => 'required|integer|min:0|max:10000',
            'reason' => 'nullable|string|max:255',
        ]);
        $stock->update(['monthly_quantity' => $data['monthly_quantity']]);
        $this->inventory->setStockQuantity($stock, $data['quantity'], $request->user(), ($data['reason'] ?? null) ?: 'Manual change');

        return back()->with('status', "Saved {$stock->prize->name} {$stock->brand}.");
    }

    public function togglePrize(Prize $prize)
    {
        $prize->update(['active' => ! $prize->active]);

        return back()->with('status', $prize->active ? "{$prize->name} is back in the box." : "{$prize->name} is hidden. It can't be drawn until you unhide it.");
    }

    /** Remove a prize for good. Past winners keep it in their history. */
    public function destroyPrize(Prize $prize)
    {
        $prize->stocks()->delete();
        $prize->delete();

        return back()->with('status', "Deleted {$prize->name}.");
    }

    public function toggleStock(GiftCardStock $stock)
    {
        $stock->update(['active' => ! $stock->active]);
        $name = "{$stock->prize->name} {$stock->brand}";

        return back()->with('status', $stock->active ? "{$name} is back in the box." : "{$name} is hidden. It can't be drawn or picked until you unhide it.");
    }

    public function destroyStock(GiftCardStock $stock)
    {
        $stock->delete();

        return back()->with('status', "Deleted {$stock->prize->name} {$stock->brand}.");
    }

    public function log()
    {
        return view('supervisor.inventory-log', [
            'logs' => InventoryLog::with('user')->latest('id')->paginate(50),
        ]);
    }

    private function validatePrize(Request $request, ?Prize $prize = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'type' => ['required', Rule::in(array_keys(Prize::TYPES))],
            'value' => 'required|numeric|min:0|max:100000',
            'pto_hours' => 'nullable|numeric|min:0|max:200',
            'taxable' => 'boolean',
            'diminishing' => 'boolean',
            'cash_bonus_allowed' => 'boolean',
            'quantity' => 'nullable|integer|min:0|max:10000',
            'monthly_quantity' => 'nullable|integer|min:0|max:10000',
        ]);

        return [
            'name' => $data['name'],
            'type' => $data['type'],
            'value_cents' => (int) round($data['value'] * 100),
            'pto_hours' => $data['type'] === 'pto' ? ($data['pto_hours'] ?? 0) : null,
            'taxable' => $request->boolean('taxable'),
            'diminishing' => $data['type'] === 'gift_card' || $request->boolean('diminishing'),
            'cash_bonus_allowed' => $request->boolean('cash_bonus_allowed'),
            'monthly_quantity' => $data['monthly_quantity'] ?? 0,
            'active' => $prize ? $prize->active : true,
        ] + (isset($data['quantity']) ? ['quantity' => (int) $data['quantity']] : []);
    }
}
