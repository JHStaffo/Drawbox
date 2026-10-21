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
     * A supervisor checked an overdue locker and the prizes were still in it.
     * They're forfeited, go back in the box, and the locker is open again.
     */
    public function forfeit(LockerAssignment $assignment, User $by): void
    {
        DB::transaction(function () use ($assignment, $by) {
            $assignment = LockerAssignment::with('locker', 'user', 'draws.prize', 'draws.user')->lockForUpdate()->findOrFail($assignment->id);
            if (! $assignment->isOverdue()) {
                throw new RuntimeException('Only a locker past its pickup date can be forfeited.');
            }

            foreach ($assignment->draws->where('status', 'in_locker') as $draw) {
                $this->machine->returnToBox($draw, $by, "Returned: {$assignment->user->name} didn't pick up from locker {$assignment->locker->number}");
            }
            $assignment->draws()->where('status', 'in_locker')->update(['status' => 'forfeited']);
            $assignment->update(['forfeited_at' => now(), 'cleared_at' => now()]);
        });
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
