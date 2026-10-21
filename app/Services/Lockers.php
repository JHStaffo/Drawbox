<?php

namespace App\Services;

use App\Models\Draw;
use App\Models\Locker;
use App\Models\LockerAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PrizesInLocker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class Lockers
{
    public function __construct(private DrawMachine $machine) {}

    /**
     * Hand one employee's prizes out, either straight to them or into a
     * locker with a combination. Locker prizes count as received when the
     * employee picks them up.
     *
     * @param  array<int>  $drawIds
     */
    public function dispense(User $employee, array $drawIds, User $by, ?Locker $locker = null, ?string $combination = null): ?LockerAssignment
    {
        $assignment = DB::transaction(function () use ($employee, $drawIds, $by, $locker, $combination) {
            $draws = Draw::lockForUpdate()->whereKey($drawIds)->where('user_id', $employee->id)->get();
            if ($draws->isEmpty() || $draws->count() !== count(array_unique($drawIds))) {
                throw new RuntimeException('Pick at least one of this employee\'s prizes.');
            }
            if ($draws->contains(fn (Draw $d) => $d->status !== 'won')) {
                throw new RuntimeException('Some of those prizes were already handed out.');
            }
            if ($locker && $draws->contains(fn (Draw $d) => $d->cash_bonus || $d->pto_hours !== null)) {
                throw new RuntimeException('PTO and cash bonuses can\'t go in a locker.');
            }

            if (! $locker) {
                Draw::whereKey($draws->modelKeys())->update(['status' => 'awarded', 'awarded_at' => now(), 'awarded_by' => $by->id]);

                return null;
            }

            $locker = Locker::lockForUpdate()->findOrFail($locker->id);
            if ($locker->current()->exists()) {
                throw new RuntimeException("Locker {$locker->number} is already in use. Please pick another.");
            }

            $assignment = LockerAssignment::create([
                'locker_id' => $locker->id,
                'user_id' => $employee->id,
                'combination' => $combination,
                'dispensed_by' => $by->id,
                'dispensed_at' => now(),
                'due_at' => now()->addDays((int) Setting::get('locker_pickup_days'))->endOfDay(),
            ]);
            Draw::whereKey($draws->modelKeys())->update(['status' => 'in_locker', 'locker_assignment_id' => $assignment->id]);

            return $assignment;
        });

        if ($assignment) {
            try {
                $employee->notify(new PrizesInLocker($assignment->load('locker', 'draws')));
            } catch (Throwable $e) {
                report($e); // The prizes are in the locker either way; the message on My draws still shows.
            }
        }

        return $assignment;
    }

    /** The employee took their prizes out. They count as received now. */
    public function pickUp(LockerAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $assignment = LockerAssignment::lockForUpdate()->findOrFail($assignment->id);
            if (! $assignment->isWaiting()) {
                throw new RuntimeException('Those prizes were already picked up or forfeited.');
            }

            $assignment->update(['picked_up_at' => now()]);
            $assignment->draws()->where('status', 'in_locker')->update([
                'status' => 'awarded',
                'awarded_at' => now(),
                'awarded_by' => $assignment->dispensed_by,
            ]);
        });
    }

    /**
     * Forfeit lockers past their pickup date. Their prizes go straight back in
     * the box; the locker stays marked until a supervisor empties it.
     * Returns how many lockers were forfeited.
     */
    public function forfeitOverdue(): int
    {
        $count = 0;
        foreach (LockerAssignment::waiting()->where('due_at', '<=', now())->with('locker', 'user', 'draws.prize', 'draws.user')->get() as $assignment) {
            DB::transaction(function () use ($assignment) {
                foreach ($assignment->draws->where('status', 'in_locker') as $draw) {
                    $this->machine->returnToBox($draw, null, "Returned: {$assignment->user->name} didn't pick up from locker {$assignment->locker->number}");
                }
                $assignment->update(['forfeited_at' => now()]);
                $assignment->draws()->where('status', 'in_locker')->update(['status' => 'forfeited']);
            });
            $count++;
        }

        return $count;
    }

    /** A supervisor took forfeited prizes out of a locker, so it's open again. */
    public function clear(LockerAssignment $assignment, User $by): void
    {
        $assignment = LockerAssignment::findOrFail($assignment->id);
        if (! $assignment->isForfeited()) {
            throw new RuntimeException('Only a forfeited locker can be emptied.');
        }
        $assignment->update(['cleared_at' => now()]);
    }

    /** Each office's lockers with what's in them now, for the maps. */
    public function map(): Collection
    {
        $lockers = Locker::with(['current.user', 'latest.user'])->orderBy('row')->orderBy('col')->get();

        return collect(Locker::OFFICES)->map(fn ($name, $office) => [
            'name' => $name,
            'lockers' => $lockers->where('office', $office)->values(),
            'cols' => $lockers->where('office', $office)->max('col') + 1,
        ]);
    }
}
