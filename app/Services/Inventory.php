<?php

namespace App\Services;

use App\Models\Draw;
use App\Models\GiftCardStock;
use App\Models\InventoryLog;
use App\Models\Prize;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Inventory
{
    /**
     * Envelopes currently in the box, keyed by prize id.
     *
     * A gift card value's envelopes are its brand cards in stock, less the
     * ones already drawn but still waiting for the winner to pick a brand.
     */
    public function envelopes(): Collection
    {
        $prizes = Prize::where('active', true)->orderBy('sort')->get();

        $stock = GiftCardStock::selectRaw('prize_id, sum(quantity) as total')
            ->groupBy('prize_id')->pluck('total', 'prize_id');

        $reserved = Draw::where('status', 'pending_brand')
            ->selectRaw('prize_id, count(*) as total')
            ->groupBy('prize_id')->pluck('total', 'prize_id');

        return $prizes->mapWithKeys(function (Prize $prize) use ($stock, $reserved) {
            $count = $prize->isGiftCard()
                ? (int) ($stock[$prize->id] ?? 0) - (int) ($reserved[$prize->id] ?? 0)
                : $prize->quantity;

            return [$prize->id => ['prize' => $prize, 'count' => max(0, $count)]];
        });
    }

    /** Each prize's chance of being drawn right now, as a fraction. */
    public function odds(): Collection
    {
        $envelopes = $this->envelopes();
        $total = $envelopes->sum('count');

        return $envelopes->map(fn ($e) => $e + ['odds' => $total ? $e['count'] / $total : 0]);
    }

    public function setPrizeQuantity(Prize $prize, int $quantity, ?User $by, string $reason): void
    {
        DB::transaction(function () use ($prize, $quantity, $by, $reason) {
            $prize = Prize::lockForUpdate()->findOrFail($prize->id);
            $change = $quantity - $prize->quantity;
            if ($change === 0) {
                return;
            }
            $prize->update(['quantity' => $quantity]);
            $this->log($by, $prize->name, $change, $quantity, $reason);
        });
    }

    public function setStockQuantity(GiftCardStock $stock, int $quantity, ?User $by, string $reason): void
    {
        DB::transaction(function () use ($stock, $quantity, $by, $reason) {
            $stock = GiftCardStock::with('prize')->lockForUpdate()->findOrFail($stock->id);
            $change = $quantity - $stock->quantity;
            if ($change === 0) {
                return;
            }
            $stock->update(['quantity' => $quantity]);
            $this->log($by, "{$stock->prize->name} {$stock->brand}", $change, $quantity, $reason);
        });
    }

    /** Top every restocked prize and gift card brand up to its monthly amount. */
    public function restock(?User $by): int
    {
        $changed = 0;

        foreach (Prize::where('active', true)->whereIn('type', ['item', 'pto'])->get() as $prize) {
            if ($prize->quantity < $prize->monthly_quantity) {
                $this->setPrizeQuantity($prize, $prize->monthly_quantity, $by, 'Monthly restock');
                $changed++;
            }
        }

        foreach (GiftCardStock::whereHas('prize', fn ($q) => $q->where('active', true))->get() as $stock) {
            if ($stock->quantity < $stock->monthly_quantity) {
                $this->setStockQuantity($stock, $stock->monthly_quantity, $by, 'Monthly restock');
                $changed++;
            }
        }

        return $changed;
    }

    public function log(?User $by, string $item, int $change, int $after, string $reason): void
    {
        InventoryLog::create([
            'user_id' => $by?->id,
            'item' => $item,
            'change' => $change,
            'quantity_after' => $after,
            'reason' => $reason,
        ]);
    }
}
