<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Models\Draw;
use App\Models\DrawCredit;
use Illuminate\Http\Request;

class AwardController extends Controller
{
    /** Mark a prize as physically handed to the employee. */
    public function store(Request $request, Draw $draw)
    {
        if ($draw->status !== 'won') {
            return back()->with('error', $draw->status === 'pending_brand'
                ? "The team member still needs to pick a {$draw->choiceNoun()}."
                : 'That prize was already marked as received.');
        }

        $draw->update(['status' => 'awarded', 'awarded_at' => now(), 'awarded_by' => $request->user()->id]);

        return back()->with('status', "Marked {$draw->label()} as received by {$draw->user->name}.");
    }

    /** Cancel a credit that dispatch entered by mistake. */
    public function voidCredit(DrawCredit $credit)
    {
        abort_unless($credit->status === 'open', 422);
        $credit->update(['status' => 'void']);

        return back()->with('status', "Removed the draw for {$credit->user->name}.");
    }
}
