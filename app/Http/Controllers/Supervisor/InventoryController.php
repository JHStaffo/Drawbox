<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\GiftCardStock;
use App\Models\InventoryLog;
use App\Models\Prize;
use App\Services\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        if (! $prize->load('stocks')->countsFromChoices() && isset($data['quantity'])) {
            $this->inventory->setPrizeQuantity($prize, $data['quantity'], $request->user(), $request->input('reason') ?: 'Manual change');
        }

        return back()->with('status', "Saved {$prize->name}.");
    }

    /**
     * Add one or more gift card brands, or choices for any other prize, in
     * one save. Rows left blank are ignored.
     */
    public function storeStock(Request $request, Prize $prize)
    {
        $noun = $prize->choiceNoun();
        $rows = collect($request->input('items', []))
            ->filter(fn ($row) => is_array($row) && trim((string) ($row['brand'] ?? '')) !== '')
            ->map(fn ($row) => ['brand' => trim($row['brand'])] + $row)
            ->values();
        $request->merge(['items' => $rows->all()]);

        $taken = $prize->stocks()->pluck('brand')->map(fn ($b) => mb_strtolower($b))->all();
        $request->validate([
            'items' => 'required|array|min:1|max:50',
            'items.*.brand' => ['required', 'string', 'max:100', 'distinct:ignore_case', function ($attr, $value, $fail) use ($taken, $prize, $noun) {
                if (in_array(mb_strtolower($value), $taken, true)) {
                    $fail("{$prize->name} already has a {$noun} called {$value}.");
                }
            }],
            'items.*.quantity' => 'nullable|integer|min:0|max:10000',
            'items.*.monthly_quantity' => 'nullable|integer|min:0|max:10000',
        ], ['items.required' => "Enter at least one {$noun}.", 'items.*.brand.distinct' => 'The same name is entered twice.']);

        DB::transaction(function () use ($rows, $prize, $request, $noun) {
            foreach ($rows as $row) {
                $stock = $prize->stocks()->create(['brand' => $row['brand'], 'monthly_quantity' => (int) ($row['monthly_quantity'] ?? 0)]);
                $this->inventory->setStockQuantity($stock, (int) ($row['quantity'] ?? 0), $request->user(), "Added {$noun}");
            }
        });

        return back()->with('status', 'Added '.$rows->pluck('brand')->join(', ', ' and ')." to {$prize->name}.");
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
