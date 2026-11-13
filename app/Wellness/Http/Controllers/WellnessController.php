<?php

namespace App\Wellness\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Wellness\Calendar;
use App\Wellness\Leaderboard;
use App\Wellness\Models\Reading;
use App\Wellness\Models\Rule;
use App\Wellness\Progress;
use App\Wellness\Wellness;
use Illuminate\Http\Request;

/** The Team Member side of Wellness: dashboard, logging, leaderboard and rules. */
class WellnessController extends Controller
{
    public function __construct(private Wellness $wellness, private Progress $progress) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $participant = $this->wellness->participant($user);
        if (! $participant?->isActive()) {
            return view('wellness.join', ['participant' => $participant, 'rules' => Rule::current(), 'version' => Wellness::rulesVersion()]);
        }

        return view('wellness.dashboard', [
            'summary' => $this->progress->summary($user),
            'participant' => $participant,
        ]);
    }

    public function join(Request $request)
    {
        $request->validate(['consent' => 'accepted'], ['consent.accepted' => 'Please check the box to agree to the rules and privacy notice.']);
        $this->wellness->join($request->user());

        return redirect()->route('wellness')->with('status', "Welcome to Wellness! Log today's habits to get your first points.");
    }

    public function withdraw(Request $request)
    {
        $this->wellness->withdraw($request->user());

        return redirect()->route('wellness')->with('status', "You've left the wellness program. Your points so far are kept, and you can rejoin anytime.");
    }

    public function log(Request $request, ?string $day = null)
    {
        $user = $request->user();
        $participant = $this->wellness->participant($user);
        if (! $participant?->isActive()) {
            return redirect()->route('wellness');
        }
        $date = $this->parseDay($day);
        $readings = Reading::where('user_id', $user->id)->where('day', $date->toDateString())->get();

        return view('wellness.log', [
            'date' => $date,
            'day' => $this->progress->day($user, $date->toDateString()),
            'manual' => $readings->where('source', 'manual')->keyBy('metric'),
            'others' => $readings->where('source', '!=', 'manual')->groupBy('metric'),
            'open' => Calendar::isOpen($date),
            'canTypeDevice' => $this->wellness->canTypeDeviceData($participant, $date->toDateString()),
            'week' => Calendar::weekDays($date),
        ]);
    }

    public function save(Request $request, string $day)
    {
        $date = $this->parseDay($day);
        $values = $request->only(array_keys(Reading::METRICS));
        // A meal plan checkbox that's unticked means "no", not "leave as is".
        $values['meal_plan'] = $request->boolean('meal_plan') ? 1 : null;
        // Sleep is typed in hours on the page and kept in minutes.
        if ($request->filled('sleep_hours')) {
            $values['sleep_minutes'] = is_numeric($request->input('sleep_hours')) ? round($request->input('sleep_hours') * 60) : $request->input('sleep_hours');
        } elseif ($request->has('sleep_hours')) {
            $values['sleep_minutes'] = null;
        }
        $result = $this->wellness->record($request->user(), $date->toDateString(), $values, 'manual', by: $request->user());
        $label = $date->format('l, M j');
        audit_note("Logged wellness for {$label}: ".($result['changes'] ? implode(', ', $result['changes']) : 'nothing changed').". Daily points now {$result['points']}.");

        return redirect()->route('wellness.log', $date->toDateString())->with('status', "Saved {$label}. You have {$result['points']} of ".\App\Wellness\Scoring::dailyMax().' daily points.');
    }

    public function leaderboard(Request $request, Leaderboard $leaderboard)
    {
        $period = $request->query('period', 'week');
        $period = array_key_exists($period, Leaderboard::PERIODS) ? $period : 'week';

        return view('wellness.leaderboard', [
            'board' => $leaderboard->standings($period),
            'period' => $period,
            'me' => $request->user()->id,
        ]);
    }

    public function rules()
    {
        return view('wellness.rules', [
            'rules' => Rule::current(),
            'version' => Wellness::rulesVersion(),
            'officialStart' => Calendar::officialStart(),
            'grace' => Calendar::graceDays(),
            'manualDevice' => Setting::get('wellness_manual_device') === '1',
        ]);
    }

    private function parseDay(?string $day)
    {
        if ($day === null) {
            return Calendar::today();
        }
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && strtotime($day), 404);

        return Calendar::date($day);
    }
}
