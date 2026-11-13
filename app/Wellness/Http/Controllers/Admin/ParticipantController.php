<?php

namespace App\Wellness\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Wellness\Calendar;
use App\Wellness\Models\Participant;
use App\Wellness\Models\Point;
use App\Wellness\Models\Reading;
use App\Wellness\Scoring;
use Illuminate\Http\Request;

/** Who is in the program, their week so far, their last data, and device exceptions. */
class ParticipantController extends Controller
{
    public function index()
    {
        [$from, $to] = Calendar::period('week');
        $participants = Participant::with('user')->get()->filter(fn ($p) => $p->user);
        $week = Point::where('category', 'daily')->where('day', '>=', $from->toDateString())->where('day', '<=', $to->toDateString())
            ->selectRaw('user_id, SUM(points) as total')->groupBy('user_id')->pluck('total', 'user_id');
        $last = Reading::selectRaw('user_id, source, MAX(updated_at) as last')->groupBy('user_id', 'source')->get()->groupBy('user_id');

        $eligible = User::where('active', true)->get()->filter(fn (User $u) => $u->hasPermission('wellness'));

        return view('wellness.admin.participants', [
            'participants' => $participants->sortBy(fn ($p) => [$p->status !== 'active', $p->user->sortName()])->values(),
            'week' => $week,
            'last' => $last,
            'target' => Scoring::weeklyTarget(),
            'notJoined' => User::byLastName($eligible->whereNotIn('id', $participants->pluck('user_id'))),
        ]);
    }

    public function exception(Request $request, Participant $participant)
    {
        $data = $request->validate([
            'manual_device_until' => 'nullable|date|after_or_equal:today|before:+1 year',
            'exception_note' => 'nullable|required_with:manual_device_until|string|max:255',
        ], ['exception_note.required_with' => 'Give a reason for the exception.']);
        $participant->update([
            'manual_device_until' => $data['manual_device_until'] ?? null,
            'exception_note' => $data['manual_device_until'] ? ($data['exception_note'] ?? null) : null,
        ]);
        $name = $participant->user->name;

        return back()->with('status', $data['manual_device_until']
            ? "{$name} can type in steps, sleep and workout through ".Calendar::date($data['manual_device_until'])->format('M j, Y').'.'
            : "{$name}'s device exception was removed.");
    }
}
