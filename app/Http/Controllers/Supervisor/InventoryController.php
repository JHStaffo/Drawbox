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

    public function resetAvailable(Request $request)
    {
        $changed = $this->inventory->resetAvailable($request->user());

        return back()->with('status', $changed ? "Reset draws left on {$changed} prize(s) to their monthly amounts." : "Every prize's draws left already match its monthly amount.");
    }

    public function storePrize(Request $request)
    {
        $data = $this->validatePrize($request);
        // Without a monthly amount, a new prize gives out what's on hand each month.
        if (! $request->filled('monthly_quantity') && ! empty($data['quantity'])) {
            $data['monthly_quantity'] = $data['quantity'];
        }
        $prize = Prize::create(collect($data)->except('quantity', 'available')->all() + ['sort' => Prize::max('sort') + 1]);
        if (! $prize->isGiftCard()) {
            $this->inventory->setPrizeQuantity($prize, $data['quantity'] ?? 0, $request->user(), 'Added prize');
        }
        if ($prize->diminishes()) {
            $this->inventory->setAvailable($prize, $data['available'] ?? $prize->monthly_quantity, $request->user(), 'Added prize');
        }

        return back()->with('status', "Added {$prize->name}.");
    }

    public function updatePrize(Request $request, Prize $prize)
    {
        $data = $this->validatePrize($request, $prize);
        $reason = $request->input('reason') ?: 'Manual change';
        $prize->update(collect($data)->except('quantity', 'available')->all());
        if (! $prize->load('stocks')->countsFromChoices() && isset($data['quantity'])) {
            $this->inventory->setPrizeQuantity($prize, $data['quantity'], $request->user(), $reason);
        }
        if ($prize->diminishes() && isset($data['available'])) {
            $this->inventory->setAvailable($prize, $data['available'], $request->user(), $reason);
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
        ], ['items.required' => "Enter at least one {$noun}.", 'items.*.brand.distinct' => 'The same name is entered twice.']);

        DB::transaction(function () use ($rows, $prize, $request, $noun) {
            foreach ($rows as $row) {
                $stock = $prize->stocks()->create(['brand' => $row['brand']]);
                $this->inventory->setStockQuantity($stock, (int) ($row['quantity'] ?? 0), $request->user(), "Added {$noun}");
            }
        });

        return back()->with('status', 'Added '.$rows->pluck('brand')->join(', ', ' and ')." to {$prize->name}.");
    }

    public function updateStock(Request $request, GiftCardStock $stock)
    {
        $data = $request->validate([
            'quantity' => 'required|integer|min:0|max:10000',
            'reason' => 'nullable|string|max:255',
        ]);
        $this->inventory->setStockQuantity($stock, $data['quantity'], $request->user(), ($data['reason'] ?? null) ?: 'Manual change');

        return back()->with('status', "Saved {$stock->prize->name} {$stock->brand}.");
    }

    /**
     * Save a gift card value's (or a prize with choices') counts in one go:
     * its draws left and monthly amount, and how many of each brand or
     * choice are on hand.
     */
    public function updateCounts(Request $request, Prize $prize)
    {
        $data = $request->validate([
            'available' => 'nullable|integer|min:0|max:10000',
            'monthly_quantity' => 'nullable|integer|min:0|max:10000',
            'stocks' => 'array',
            'stocks.*' => 'required|integer|min:0|max:10000',
        ], ['stocks.*.required' => 'Enter how many are on hand for every '.$prize->choiceNoun().'.']);

        DB::transaction(function () use ($data, $prize, $request) {
            if (isset($data['monthly_quantity'])) {
                $prize->update(['monthly_quantity' => $data['monthly_quantity']]);
            }
            if (isset($data['available']) && $prize->diminishes()) {
                $this->inventory->setAvailable($prize, $data['available'], $request->user(), 'Manual change');
            }
            $stocks = $prize->stocks()->whereIn('id', array_keys($data['stocks'] ?? []))->get();
            foreach ($stocks as $stock) {
                $this->inventory->setStockQuantity($stock, $data['stocks'][$stock->id], $request->user(), 'Manual change');
            }
        });

        return back()->with('status', "Saved {$prize->name}.");
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
            'available' => 'nullable|integer|min:0|max:10000',
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
            'monthly_quantity' => (int) ($data['monthly_quantity'] ?? $prize?->monthly_quantity ?? 0),
            'active' => $prize ? $prize->active : true,
        ] + (isset($data['quantity']) ? ['quantity' => (int) $data['quantity']] : [])
          + (isset($data['available']) ? ['available' => (int) $data['available']] : []);
    }
}
