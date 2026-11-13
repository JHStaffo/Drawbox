<?php

namespace App\Wellness\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Wellness\Calendar;
use App\Wellness\Models\Participant;
use App\Wellness\Models\Point;
use App\Wellness\Models\Reading;
use App\Wellness\Models\Tester;
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

        $eligible = User::where('active', true)->get()->filter(fn (User $u) => \App\Wellness\Wellness::canUse($u));

        return view('wellness.admin.participants', [
            'participants' => $participants->sortBy(fn ($p) => [$p->status !== 'active', $p->user->sortName()])->values(),
            'week' => $week,
            'last' => $last,
            'target' => Scoring::weeklyTarget(),
            'notJoined' => User::byLastName($eligible->whereNotIn('id', $participants->pluck('user_id'))),
            'testers' => Tester::with('user', 'addedBy')->get()->filter(fn ($t) => $t->user)->sortBy(fn ($t) => $t->user->sortName())->values(),
            'canAdd' => User::byLastName(User::where('active', true)->whereNotIn('id', Tester::pluck('user_id'))->get()),
        ]);
    }

    /** One Team Member's points ledger, submissions and rewards, for questions and disputes. */
    public function show(Participant $participant)
    {
        $user = $participant->user;
        abort_unless($user, 404);

        return view('wellness.admin.participant', [
            'participant' => $participant,
            'user' => $user,
            'points' => Point::with('rule')->where('user_id', $user->id)->orderByDesc('day')->orderByDesc('id')->limit(400)->get(),
            'submissions' => \App\Wellness\Models\Submission::with('reviewer')->where('user_id', $user->id)->latest('id')->get(),
            'awards' => \App\Wellness\Models\Award::where('user_id', $user->id)->latest('period_start')->get(),
            'adjusters' => User::whereIn('id', Point::where('user_id', $user->id)->whereNotNull('created_by')->pluck('created_by'))->pluck('name', 'id'),
            'names' => \App\Wellness\Models\Rule::pluck('name', 'key'),
        ]);
    }

    public function adjust(Request $request, Participant $participant, \App\Wellness\Adjustments $adjustments)
    {
        $data = $request->validate([
            'day' => 'required|date',
            'category' => 'required|in:daily,other',
            'points' => 'required|integer|between:-1000,1000',
            'reason' => 'required|string|max:255',
        ], ['reason.required' => 'Every adjustment needs a reason.']);
        $user = $participant->user;
        $point = $adjustments->add($user, $data['day'], $data['category'], (int) $data['points'], trim($data['reason']), $request->user());
        $signed = ($point->points > 0 ? '+' : '').$point->points;
        $kind = $data['category'] === 'daily' ? 'daily-habit' : 'other-activity';
        audit_note("Adjusted {$user->name}'s {$kind} points for ".Calendar::date($point->day)->format('M j, Y')." by {$signed}. Reason: {$point->reason}");

        return back()->with('status', "Adjusted {$user->name}'s points by {$signed}.");
    }

    /** Give one Team Member Wellness for testing, without changing their permission profile. */
    public function addTester(Request $request)
    {
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id'], ['user_id.required' => 'Pick a Team Member.']);
        $user = User::findOrFail($data['user_id']);
        if (Tester::where('user_id', $user->id)->exists()) {
            return back()->with('status', "{$user->name} can already use Wellness.");
        }
        Tester::create(['user_id' => $user->id, 'added_by' => $request->user()->id]);
        audit_note("Gave {$user->name} Wellness individually for testing.");

        return back()->with('status', "{$user->name} can now sign up for Wellness.");
    }

    public function removeTester(Tester $tester)
    {
        $user = $tester->user;
        $tester->delete();
        $still = $user && $user->hasPermission('wellness');
        audit_note("Took individual Wellness access away from {$user?->name}.".($still ? ' Their permission profile still includes Wellness.' : ''));

        return back()->with('status', $still
            ? "Removed {$user->name} from the testers. Their permission profile still gives them Wellness."
            : "Removed {$user?->name} from the testers. They can no longer use Wellness, but their points are kept.");
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
