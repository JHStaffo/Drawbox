<?php

namespace App\Wellness\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Wellness\Calendar;
use App\Wellness\Models\Award;
use App\Wellness\Models\Submission;
use App\Wellness\Rewards;
use App\Wellness\Submissions;
use Illuminate\Http\Request;

/** Visits, InBody scans and weigh-ins a Team Member sends in, and their rewards. */
class ActivityController extends Controller
{
    public function __construct(private Submissions $submissions, private Rewards $rewards) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $fixing = $request->query('fix') ? Submission::where('user_id', $user->id)->where('status', 'rejected')->find($request->query('fix')) : null;

        return view('wellness.activities', [
            'activities' => Submissions::activities(),
            'submissions' => Submission::where('user_id', $user->id)->latest('id')->limit(100)->get(),
            'awards' => Award::where('user_id', $user->id)->latest('period_start')->get(),
            'brands' => Rewards::brands(),
            'fixing' => $fixing,
            'oldest' => Calendar::today()->subDays((int) Setting::get('wellness_submit_days')),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'activity' => 'required|string',
            'day' => 'required|date',
            'note' => 'nullable|'.Submissions::NOTE_RULE,
            'body_fat_pct' => 'nullable',
            'muscle_lb' => 'nullable',
            'comment' => 'nullable|string|max:500',
            'fix' => 'nullable|integer',
        ], ['note.mimes' => 'The note has to be a photo (JPG, PNG or HEIC) or a PDF.', 'note.max' => 'The note can be up to 10 MB.']);
        $fixing = isset($data['fix']) ? Submission::find($data['fix']) : null;

        $submission = $this->submissions->submit(
            $request->user(), $data['activity'], $data['day'], $request->file('note'),
            ['body_fat_pct' => $data['body_fat_pct'] ?? null, 'muscle_lb' => $data['muscle_lb'] ?? null],
            $data['comment'] ?? null, $fixing,
        );
        $what = Submissions::describe($submission);
        if ($submission->status === 'approved') {
            audit_note("Recorded a {$what}: {$submission->points} points.");

            return redirect()->route('wellness.activities')->with('status', "Recorded your {$what}: {$submission->points} points.".($submission->points_note ? ' '.$submission->points_note : ''));
        }
        audit_note("Sent a {$what} for review".($submission->note_path ? ' with an attached note' : '').($fixing ? ", fixing #{$fixing->id}" : '').'.');

        return redirect()->route('wellness.activities')->with('status', "Sent your {$what} for review. You'll see the result here.");
    }

    public function withdraw(Request $request, Submission $submission)
    {
        abort_unless($submission->user_id === $request->user()->id, 404);
        $this->submissions->withdraw($submission);
        audit_note('Withdrew a '.Submissions::describe($submission).'.');

        return back()->with('status', 'Withdrawn.');
    }

    /** The Team Member's own uploaded note. */
    public function note(Request $request, Submission $submission)
    {
        abort_unless($submission->user_id === $request->user()->id, 404);

        return $this->download($submission, $request);
    }

    public function brand(Request $request, Award $award)
    {
        $brand = $request->validate(['brand' => 'required|string'])['brand'];
        $this->rewards->chooseBrand($award, $request->user(), $brand);
        audit_note("Picked {$brand} for the ".$award->label().'.');

        return back()->with('status', "Got it: {$brand}. An administrator will send it.");
    }

    /** Streams a decrypted note and records who looked at it. */
    public static function download(Submission $submission, Request $request)
    {
        $contents = app(Submissions::class)->noteContents($submission);
        abort_unless($contents !== null, 404);
        AuditLog::record('wellness.note.view', 'Opened the note for '.($submission->user?->name ?? 'a Team Member')."'s ".Submissions::describe($submission).'.', $request->user());

        return response($contents, 200, [
            'Content-Type' => $submission->note_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $submission->note_name ?: 'note').'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
