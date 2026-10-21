<?php

namespace App\Services;

use App\Models\Draw;
use App\Models\DrawCredit;
use App\Models\GiftCardStock;
use App\Models\Prize;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DrawMachine
{
    public function __construct(private Inventory $inventory) {}

    /**
     * Draw one envelope for a credit. Every envelope left in the box is
     * equally likely, so a prize's odds are its envelopes over the total.
     */
    public function draw(DrawCredit $credit): Draw
    {
        return DB::transaction(function () use ($credit) {
            $credit = DrawCredit::lockForUpdate()->findOrFail($credit->id);
            if (! $credit->isAvailable()) {
                throw new RuntimeException('This draw is no longer available.');
            }

            // Serialize draws so two people can't take the last envelope.
            Prize::lockForUpdate()->get();

            $envelopes = $this->inventory->envelopes()->filter(fn ($e) => $e['count'] > 0);
            $total = $envelopes->sum('count');
            if ($total === 0) {
                throw new RuntimeException('The draw box is empty. Please let a supervisor know.');
            }

            $pick = random_int(1, $total);
            foreach ($envelopes as $envelope) {
                $pick -= $envelope['count'];
                if ($pick <= 0) {
                    break;
                }
            }

            /** @var Prize $prize */
            $prize = $envelope['prize'];

            if (! $prize->isGiftCard() && $prize->diminishing) {
                $prize->decrement('quantity');
                $this->inventory->log($credit->user, $prize->name, -1, $prize->quantity, "Drawn by {$credit->user->name}");
            }

            $credit->update(['status' => 'drawn']);

            return Draw::create([
                'draw_credit_id' => $credit->id,
                'user_id' => $credit->user_id,
                'prize_id' => $prize->id,
                'prize_name' => $prize->name,
                'prize_type' => $prize->type,
                'value_cents' => $prize->value_cents,
                'pto_hours' => $prize->pto_hours,
                'taxable' => $prize->taxable,
                'status' => $prize->isGiftCard() ? 'pending_brand' : 'won',
                'drawn_at' => now(),
            ]);
        });
    }

    /**
     * Swap a won prize for a taxable cash bonus of the same value. Whatever
     * the prize took out of the box goes back in.
     */
    public function takeCashBonus(Draw $draw): Draw
    {
        return DB::transaction(function () use ($draw) {
            $draw = Draw::with('prize', 'user')->lockForUpdate()->findOrFail($draw->id);
            if (! $draw->canTakeCashBonus()) {
                throw new RuntimeException('This prize can\'t be swapped for a cash bonus.');
            }

            $reason = "Returned: {$draw->user->name} took a cash bonus";
            if ($draw->gift_card_stock_id) {
                $stock = GiftCardStock::lockForUpdate()->findOrFail($draw->gift_card_stock_id);
                $stock->increment('quantity');
                $this->inventory->log($draw->user, "{$draw->prize_name} {$stock->brand}", 1, $stock->quantity, $reason);
            } elseif (! $draw->prize->isGiftCard() && $draw->prize->diminishing) {
                $prize = Prize::withTrashed()->lockForUpdate()->findOrFail($draw->prize_id);
                $prize->increment('quantity');
                $this->inventory->log($draw->user, $prize->name, 1, $prize->quantity, $reason);
            }

            $draw->update([
                'cash_bonus' => true,
                'taxable' => true,
                'pto_hours' => null,
                'gift_card_stock_id' => null,
                'brand' => null,
                'status' => 'won',
            ]);

            return $draw;
        });
    }

    /**
     * Take a prize back from an employee (an admin correcting a mistake).
     * The draw is deleted, the credit is voided rather than reopened, and
     * whatever the prize took out of the box goes back in unless it was
     * already handed out.
     */
    public function removeDraw(Draw $draw, User $by): void
    {
        DB::transaction(function () use ($draw, $by) {
            $draw = Draw::with('prize', 'user', 'credit')->lockForUpdate()->findOrFail($draw->id);
            // A prize already handed out isn't in the building any more, so it stays out of the box.
            if ($draw->status !== 'awarded') {
                $this->returnToBox($draw, $by, "Returned: {$by->name} removed {$draw->user->name}'s prize");
            }

            $draw->credit->update(['status' => 'void']);
            $draw->delete();
        });
    }

    /**
     * Give an employee a different prize (an admin correcting a mistake).
     * The old envelope goes back in the box and the new one comes out, and
     * the new prize's details are copied onto the draw like a real draw.
     * $details can override value_cents, pto_hours and taxable.
     */
    public function changePrize(Draw $draw, Prize $prize, ?GiftCardStock $stock, User $by, array $details = []): Draw
    {
        return DB::transaction(function () use ($draw, $prize, $stock, $by, $details) {
            $draw = Draw::with('prize', 'user')->lockForUpdate()->findOrFail($draw->id);
            $stock = $prize->isGiftCard() && ! $draw->cash_bonus ? $stock : null;

            $samePrize = $draw->prize_id === $prize->id && $draw->gift_card_stock_id === $stock?->id;
            if (! $samePrize && ! $draw->cash_bonus) {
                $reason = "Changed by {$by->name}: {$draw->user->name}'s prize";
                $this->returnToBox($draw, $by, $reason);
                $this->takeFromBox($prize, $stock, $by, $reason);
            }

            $status = $draw->status;
            if ($prize->isGiftCard() && ! $draw->cash_bonus && ! $stock) {
                $status = 'pending_brand';
            } elseif ($status === 'pending_brand') {
                $status = 'won';
            }

            $draw->update([
                'prize_id' => $prize->id,
                'prize_name' => $prize->name,
                'prize_type' => $prize->type,
                'value_cents' => $details['value_cents'] ?? $prize->value_cents,
                'pto_hours' => $draw->cash_bonus ? null : (array_key_exists('pto_hours', $details) ? $details['pto_hours'] : $prize->pto_hours),
                'taxable' => $draw->cash_bonus ? true : ($details['taxable'] ?? $prize->taxable),
                'gift_card_stock_id' => $stock?->id,
                'brand' => $stock?->brand,
                'status' => $status,
            ]);

            return $draw;
        });
    }

    /** Put back whatever a draw took out of the box. */
    public function returnToBox(Draw $draw, ?User $by, string $reason): void
    {
        if ($draw->cash_bonus) {
            return;
        }

        if ($draw->gift_card_stock_id) {
            $stock = GiftCardStock::lockForUpdate()->find($draw->gift_card_stock_id);
            if ($stock) {
                $stock->increment('quantity');
                $this->inventory->log($by, "{$draw->prize_name} {$stock->brand}", 1, $stock->quantity, $reason);
            }
        } elseif (! $draw->prize->isGiftCard() && $draw->prize->diminishing) {
            $prize = Prize::withTrashed()->lockForUpdate()->findOrFail($draw->prize_id);
            $prize->increment('quantity');
            $this->inventory->log($by, $prize->name, 1, $prize->quantity, $reason);
        }
    }

    /** Take one envelope out of the box for a prize given by hand. Counts never go below zero. */
    private function takeFromBox(Prize $prize, ?GiftCardStock $stock, User $by, string $reason): void
    {
        if ($stock) {
            $stock = GiftCardStock::lockForUpdate()->findOrFail($stock->id);
            if ($stock->quantity > 0) {
                $stock->decrement('quantity');
                $this->inventory->log($by, "{$prize->name} {$stock->brand}", -1, $stock->quantity, $reason);
            }
        } elseif (! $prize->isGiftCard() && $prize->diminishing) {
            $prize = Prize::withTrashed()->lockForUpdate()->findOrFail($prize->id);
            if ($prize->quantity > 0) {
                $prize->decrement('quantity');
                $this->inventory->log($by, $prize->name, -1, $prize->quantity, $reason);
            }
        }
    }

    /** Brands the winner can still pick for a gift card they drew. */
    public function brandsFor(Draw $draw)
    {
        return GiftCardStock::where('prize_id', $draw->prize_id)->where('active', true)->where('quantity', '>', 0)->orderBy('brand')->get();
    }

    public function chooseBrand(Draw $draw, int $stockId): Draw
    {
        return DB::transaction(function () use ($draw, $stockId) {
            $draw = Draw::lockForUpdate()->findOrFail($draw->id);
            if ($draw->status !== 'pending_brand') {
                throw new RuntimeException('A brand was already chosen for this prize.');
            }

            $stock = GiftCardStock::lockForUpdate()->where('prize_id', $draw->prize_id)->where('active', true)->findOrFail($stockId);
            if ($stock->quantity < 1) {
                throw new RuntimeException('That brand just ran out. Please pick another.');
            }

            $stock->decrement('quantity');
            $this->inventory->log($draw->user, "{$draw->prize_name} {$stock->brand}", -1, $stock->quantity, "Chosen by {$draw->user->name}");

            $draw->update(['gift_card_stock_id' => $stock->id, 'brand' => $stock->brand, 'status' => 'won']);

            return $draw;
        });
    }
}
