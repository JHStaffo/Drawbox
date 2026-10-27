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
     * A diminishing prize has two numbers: draws left this month, which is
     * lowered by every draw (a cash bonus included), and how many are on
     * hand, which is lowered only when the prize itself is taken. Its odds
     * come from the draws left, but never more than are on hand, so a
     * prize at 0 of either drops out of the drawing.
     *
     * On hand for a prize with choices (gift cards) is the choices in stock,
     * less the ones already drawn but still waiting on a pick.
     * A non-diminishing prize (candy) never runs out: its own count only
     * sets the odds, and with choices it needs some choice in stock.
     */
    public function envelopes(): Collection
    {
        $prizes = Prize::where('active', true)->with('stocks')->orderBy('sort')->get();
        $onHand = $this->onHand($prizes);

        return $prizes->mapWithKeys(function (Prize $prize) use ($onHand) {
            $have = $onHand[$prize->id];
            if ($prize->diminishes()) {
                $count = min($prize->available, $have);
            } else {
                $count = ! $prize->hasChoices() || $have > 0 ? $prize->quantity : 0;
            }

            return [$prize->id => [
                'prize' => $prize,
                'count' => max(0, $count),
                'left' => $prize->diminishes() ? $prize->available : null,
                'on_hand' => $have,
            ]];
        });
    }

    /**
     * How many of each prize are on hand and not yet promised to a winner,
     * keyed by prize id. For a prize with choices it is the choices in
     * stock less draws still waiting on a pick; otherwise its own count.
     */
    public function onHand(Collection $prizes): Collection
    {
        $stock = GiftCardStock::where('active', true)->selectRaw('prize_id, sum(quantity) as total')
            ->groupBy('prize_id')->pluck('total', 'prize_id');

        $reserved = Draw::where('status', 'pending_brand')
            ->selectRaw('prize_id, count(*) as total')
            ->groupBy('prize_id')->pluck('total', 'prize_id');

        return $prizes->mapWithKeys(fn (Prize $prize) => [$prize->id => $prize->hasChoices()
            ? max(0, (int) ($stock[$prize->id] ?? 0) - (int) ($reserved[$prize->id] ?? 0))
            : (int) $prize->quantity]);
    }

    /**
     * Dollar value of every envelope in the box right now. Prizes already
     * won, including gift cards waiting on a brand pick, are not counted.
     */
    public function boxValueCents(): int
    {
        return (int) $this->envelopes()->sum(fn ($e) => $e['count'] * $e['prize']->value_cents);
    }

    /** Each prize's chance of being drawn right now, as a fraction. */
    public function odds(): Collection
    {
        $envelopes = $this->envelopes();
        $total = $envelopes->sum('count');

        return $envelopes->map(fn ($e) => $e + ['odds' => $total ? $e['count'] / $total : 0]);
    }

    /** Set how many of a prize are on hand. */
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

    /** Set how many of a brand or choice are on hand. */
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

    /** Set a prize's draws left. */
    public function setAvailable(Prize $prize, int $available, ?User $by, string $reason): void
    {
        DB::transaction(function () use ($prize, $available, $by, $reason) {
            $prize = Prize::withTrashed()->lockForUpdate()->findOrFail($prize->id);
            $available = max(0, $available);
            $change = $available - $prize->available;
            if ($change === 0) {
                return;
            }
            $prize->update(['available' => $available]);
            $this->log($by, "{$prize->name} (draws left)", $change, $available, $reason);
        });
    }

    /**
     * Set every prize's draws left back to its monthly amount. What's on
     * hand doesn't change: a prize with fewer on hand than its monthly
     * amount can still only be drawn as many times as there are on hand.
     */
    public function resetAvailable(?User $by): int
    {
        $changed = 0;

        foreach (Prize::where('active', true)->get()->filter->diminishes() as $prize) {
            if ($prize->available !== $prize->monthly_quantity) {
                $this->setAvailable($prize, $prize->monthly_quantity, $by, 'Monthly reset');
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
