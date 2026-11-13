<?php

namespace App\Wellness\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Wellness\Calendar;
use App\Wellness\Leaderboard;
use App\Wellness\Models\Reading;
use App\Wellness\Models\Rule;
use App\Wellness\Progress;
use App\Wellness\Scoring;
use App\Wellness\Wellness;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\ValidationException;

/**
 * Wellness over JSON, for phone apps and the Apple Health Shortcut. Same
 * services as the web pages, so points always match.
 */
class WellnessApiController extends Controller
{
    public function __construct(private Wellness $wellness, private Progress $progress) {}

    public function rules()
    {
        return response()->json([
            'version' => Wellness::rulesVersion(),
            'daily_max' => Scoring::dailyMax(),
            'weekly_target' => Scoring::weeklyTarget(),
            'week_starts' => 'sunday',
            'grace_days' => Calendar::graceDays(),
            'official_start' => Calendar::officialStart()->toDateString(),
            'metrics' => collect(Reading::METRICS)->map(fn ($m, $key) => ['key' => $key, 'name' => $m[0], 'unit' => $m[1], 'max' => Reading::LIMITS[$key], 'from_device' => in_array($key, Reading::DEVICE_METRICS, true)])->values(),
            'rules' => Rule::current()->map(fn (Rule $r) => [
                'key' => $r->key, 'name' => $r->name, 'category' => $r->category, 'metric' => $r->metric,
                'threshold' => $r->threshold, 'unit' => $r->unit, 'points' => $r->points, 'per_unit' => $r->per_unit,
                'max_per_day' => $r->max_per_day, 'cap_points' => $r->cap_points, 'cap_period' => $r->cap_period,
                'needs_note' => $r->needs_note, 'description' => $r->description, 'effective_from' => $r->effective_from->toDateString(),
            ])->values(),
        ]);
    }

    public function join(Request $request)
    {
        $request->validate(['consent' => 'accepted']);
        $this->wellness->join($request->user());

        return response()->json(['user' => MeController::profile($request->user())]);
    }

    public function summary(Request $request)
    {
        $this->requireParticipant($request);

        return response()->json($this->progress->summary($request->user()));
    }

    public function days(Request $request)
    {
        $this->requireParticipant($request);
        $data = $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = Calendar::date($data['from']);
        $to = Calendar::date($data['to']);
        if ($from->diffInDays($to) > 92) {
            throw ValidationException::withMessages(['to' => 'Ask for 93 days or fewer at a time.']);
        }
        $days = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $days[] = $this->progress->day($request->user(), $d->toDateString());
        }

        return response()->json(['days' => $days]);
    }

    public function day(Request $request, string $day)
    {
        $this->requireParticipant($request);

        return response()->json($this->progress->day($request->user(), $this->validDay($day)));
    }

    /** Typed-in values for one day, as the Log page saves them. */
    public function updateDay(Request $request, string $day)
    {
        $this->requireParticipant($request);
        $day = $this->validDay($day);
        $values = $request->only(array_keys(Reading::METRICS));
        $result = $this->wellness->record($request->user(), $day, $values, 'manual', by: $request->user());
        audit_note('Logged wellness on the app for '.Calendar::date($day)->format('l, M j').': '.($result['changes'] ? implode(', ', $result['changes']) : 'nothing changed').". Daily points now {$result['points']}.");

        return response()->json($this->progress->day($request->user(), $day));
    }

    /**
     * Readings from a device: the Apple Health Shortcut now, phone apps later.
     * Each reading is a day's total for a metric; sending it again replaces it.
     */
    public function readings(Request $request)
    {
        $this->requireParticipant($request);
        $data = $request->validate([
            'source' => ['required', ValidationRule::in(['apple_health'])],
            'device' => 'nullable|string|max:100',
            'readings' => 'required|array|min:1|max:200',
            'readings.*.day' => 'required|date_format:Y-m-d',
            'readings.*.metric' => ['required', ValidationRule::in([...Reading::DEVICE_METRICS, 'water_oz'])],
            'readings.*.value' => 'required|numeric|min:0',
            'readings.*.recorded_at' => 'nullable|date',
        ]);

        $accepted = [];
        $skipped = [];
        foreach (collect($data['readings'])->groupBy('day') as $day => $rows) {
            if (! Calendar::isOpen($day)) {
                $skipped[] = ['day' => $day, 'reason' => Calendar::date($day)->isFuture() ? 'future' : 'closed'];

                continue;
            }
            $values = $rows->mapWithKeys(fn ($r) => [$r['metric'] => $r['value']])->all();
            try {
                $result = $this->wellness->record($request->user(), $day, $values, $data['source'], $data['device'] ?? null, recordedAt: $rows->max('recorded_at'));
                $accepted[] = ['day' => $day, 'points' => $result['points'], 'changes' => $result['changes']];
            } catch (ValidationException $e) {
                $skipped[] = ['day' => $day, 'reason' => collect($e->errors())->flatten()->implode(' ')];
            }
        }
        $source = Reading::SOURCES[$data['source']];
        audit_note("{$source} sync: ".count($accepted).' day(s) saved'.($skipped ? ', '.count($skipped).' skipped' : '').'. '
            .collect($accepted)->filter(fn ($a) => $a['changes'])->map(fn ($a) => $a['day'].': '.implode(', ', $a['changes']))->implode('; '));

        return response()->json(['accepted' => $accepted, 'skipped' => $skipped]);
    }

    public function leaderboard(Request $request, Leaderboard $leaderboard)
    {
        $period = $request->validate(['period' => ['nullable', ValidationRule::in(array_keys(Leaderboard::PERIODS))]])['period'] ?? 'week';

        return response()->json($leaderboard->standings($period));
    }

    private function requireParticipant(Request $request): void
    {
        if (! $this->wellness->participant($request->user())?->isActive()) {
            abort(response()->json(['message' => 'Join the wellness program first.', 'code' => 'not_joined'], 409));
        }
    }

    private function validDay(string $day): string
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && strtotime($day), 404);

        return $day;
    }
}
