<?php

namespace App\Wellness\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Wellness\Calendar;
use App\Wellness\Models\Award;
use App\Wellness\Models\Point;
use App\Wellness\Models\Rule;
use App\Wellness\Rewards;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Weekly rewards to send, and the CSV reports. */
class RewardController extends Controller
{
    public function __construct(private Rewards $rewards) {}

    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');
        $query = Award::with('user', 'sender')->latest('period_start')->orderBy('id');
        if (array_key_exists($status, Award::STATUSES)) {
            $query->where('status', $status);
        }

        return view('wellness.admin.rewards', [
            'awards' => $query->limit(500)->get(),
            'status' => $status,
            'counts' => Award::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'lastWeek' => Calendar::weekStart(Calendar::today()->subDays(7)),
        ]);
    }

    public function sent(Request $request, Award $award)
    {
        $note = $request->validate(['note' => 'nullable|string|max:255'])['note'] ?? null;
        $this->rewards->markSent($award, $request->user(), $note);
        audit_note("Marked {$award->user->name}'s ".$award->label().' as sent'.($award->brand ? " ({$award->brand})" : '').($note ? ". Note: {$note}" : '').'.');

        return back()->with('status', "Marked {$award->user->name}'s reward as sent.");
    }

    public function void(Request $request, Award $award)
    {
        $reason = $request->validate(['reason' => 'required|string|max:255'], ['reason.required' => 'Say why the reward is being voided.'])['reason'];
        $this->rewards->void($award, $request->user(), $reason);
        audit_note("Voided {$award->user->name}'s ".$award->label().". Reason: {$reason}");

        return back()->with('status', "Voided {$award->user->name}'s reward.");
    }

    /** Every reward, for fulfillment and tax records. */
    public function csv(): StreamedResponse
    {
        $awards = Award::with('user', 'sender')->orderBy('period_start')->orderBy('id')->get();

        return $this->stream('wellness-rewards.csv', ['Team Member', 'Email', 'Reward', 'Week start', 'Week end', 'Points', 'Value', 'Test period', 'Gift card', 'Status', 'Sent', 'Sent or voided by', 'Note'],
            $awards->map(fn (Award $a) => [
                $a->user?->name, $a->user?->email, $a->kind === 'weekly' ? 'Weekly gift card' : $a->kind,
                $a->period_start->toDateString(), $a->period_end->toDateString(), $a->points, number_format($a->value_cents / 100, 2, '.', ''),
                $a->test_period ? 'yes' : 'no', $a->brand, $a->statusLabel(), $a->sent_at?->format('Y-m-d H:i'), $a->sender?->name, $a->status_note,
            ]));
    }

    /** Points by Team Member and category for one Sunday to Saturday week. */
    public function weeklyCsv(Request $request): StreamedResponse
    {
        $week = Calendar::weekStart($request->query('week') ?: Calendar::today()->subDays(7));
        $end = $week->addDays(6);
        $daily = Rule::forDay($end->toDateString())->filter->isDaily();
        $points = Point::where('day', '>=', $week->toDateString())->where('day', '<=', $end->toDateString())->get()->groupBy('user_id');
        $users = User::byLastName(User::whereIn('id', $points->keys())->get());
        $target = \App\Wellness\Scoring::weeklyTarget();

        $rows = $users->map(function (User $u) use ($points, $daily, $target) {
            $mine = $points[$u->id];
            $earned = $mine->where('kind', 'earned');
            $dailyTotal = (int) $mine->where('category', 'daily')->sum('points');

            return [
                $u->name, $u->email,
                ...$daily->map(fn (Rule $r) => (int) $earned->where('activity', $r->key)->sum('points'))->values()->all(),
                (int) $mine->where('category', 'daily')->where('kind', '!=', 'earned')->sum('points'),
                $dailyTotal,
                (int) $mine->where('category', 'other')->sum('points'),
                (int) $mine->sum('points'),
                $dailyTotal >= $target ? 'yes' : 'no',
            ];
        });

        return $this->stream('wellness-week-'.$week->toDateString().'.csv',
            ['Team Member', 'Email', ...$daily->pluck('name')->values()->all(), 'Daily adjustments', 'Daily total', 'Other activities', 'Total', "Reached {$target}"],
            $rows);
    }

    private function stream(string $name, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
