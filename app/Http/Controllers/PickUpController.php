<?php

namespace App\Http\Controllers;

use App\Models\LockerAssignment;
use App\Services\Lockers;
use Illuminate\Http\Request;
use RuntimeException;

class PickUpController extends Controller
{
    /** The employee pressed Picked Up on their own locker. */
    public function store(Request $request, LockerAssignment $assignment, Lockers $lockers)
    {
        abort_unless($assignment->user_id === $request->user()->id, 403);

        try {
            $lockers->pickUp($assignment);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Thanks! Your prizes are marked as picked up. Please leave the lock on the locker for the next person.');
    }
}
