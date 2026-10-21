<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\Draw;
use App\Models\Locker;
use App\Models\LockerAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Lockers;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Handing an employee's prizes out, directly or through a locker. */
class DispenseController extends Controller
{
    public function create(Request $request, User $user, Lockers $lockers)
    {
        $ids = array_map('intval', (array) $request->query('draws', []));
        $draws = Draw::whereKey($ids)->where('user_id', $user->id)->where('status', 'won')->where('cash_bonus', false)->whereNull('pto_hours')->get();
        if ($draws->isEmpty()) {
            return redirect()->route('supervisor.dashboard')->with('error', "Check at least one of {$user->name}'s prizes to dispense.");
        }

        return view('supervisor.dispense', [
            'employee' => $user,
            'draws' => $draws,
            'offices' => $lockers->map(),
            'pickupDays' => (int) Setting::get('locker_pickup_days'),
        ]);
    }

    public function store(Request $request, User $user, Lockers $lockers)
    {
        $data = $request->validate([
            'draws' => 'required|array|min:1',
            'draws.*' => 'integer',
            'method' => ['required', Rule::in(['direct', 'locker'])],
            'office' => ['required_if:method,locker', 'nullable', Rule::in(array_keys(Locker::OFFICES))],
            'locker_id' => ['required_if:method,locker', 'nullable', Rule::exists('lockers', 'id')->where('office', $request->input('office'))],
            'combination' => 'required_if:method,locker|nullable|array|size:3',
            'combination.*' => 'required_if:method,locker|nullable|integer|min:0|max:999',
        ], [
            'locker_id.required_if' => 'Pick the locker you are putting the prizes in.',
            'locker_id.exists' => 'Pick a locker at the office you chose.',
            'office.required_if' => 'Pick the office the locker is at.',
            'combination.*.required_if' => 'Enter all three numbers of the combination.',
        ]);

        $locker = $data['method'] === 'locker' ? Locker::find($data['locker_id']) : null;
        $combination = $locker ? implode(' ', array_map('intval', $data['combination'])) : null;

        try {
            $assignment = $lockers->dispense($user, $data['draws'], $request->user(), $locker, $combination);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('supervisor.dashboard')->with('status', $assignment
            ? "Put {$user->name}'s prizes in locker {$locker->number} at {$locker->officeName()} and emailed them the combination."
            : "Marked {$user->name}'s prizes as handed out.");
    }

    public function pickUp(LockerAssignment $assignment, Lockers $lockers)
    {
        try {
            $lockers->pickUp($assignment);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Marked locker {$assignment->locker->number} as picked up by {$assignment->user->name}.");
    }

    public function forfeit(Request $request, LockerAssignment $assignment, Lockers $lockers)
    {
        try {
            $lockers->forfeit($assignment, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$assignment->user->name}'s prizes from locker {$assignment->locker->number} are forfeited and back in the box. The locker is open again.");
    }
}
