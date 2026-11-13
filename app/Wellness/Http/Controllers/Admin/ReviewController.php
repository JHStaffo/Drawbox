<?php

namespace App\Wellness\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Wellness\Http\Controllers\ActivityController;
use App\Wellness\Models\Submission;
use App\Wellness\Submissions;
use Illuminate\Http\Request;

/** The review queue: attendance notes, InBody scans and fixed-up resubmissions. */
class ReviewController extends Controller
{
    public function __construct(private Submissions $submissions) {}

    public function index()
    {
        return view('wellness.admin.review', [
            'pending' => Submission::with('user', 'original')->where('status', 'pending')->oldest('id')->get(),
            'recent' => Submission::with('user', 'reviewer')->whereIn('status', ['approved', 'rejected'])->whereNotNull('reviewed_by')->latest('reviewed_at')->limit(30)->get(),
            'names' => \App\Wellness\Models\Rule::pluck('name', 'key'),
        ]);
    }

    public function note(Request $request, Submission $submission)
    {
        return ActivityController::download($submission->load('user'), $request);
    }

    public function approve(Request $request, Submission $submission)
    {
        $points = $this->submissions->approve($submission, $request->user());
        $submission->refresh();
        $what = $submission->user->name."'s ".Submissions::describe($submission);
        audit_note("Approved {$what}: {$points} points.".($submission->points_note ? ' '.$submission->points_note : ''));

        return back()->with('status', "Approved {$what}: {$points} points.".($submission->points_note ? ' '.$submission->points_note : ''));
    }

    public function reject(Request $request, Submission $submission)
    {
        $reason = trim($request->validate(['reason' => 'required|string|max:500'], ['reason.required' => 'Say why, so the Team Member can fix it.'])['reason']);
        $this->submissions->reject($submission, $request->user(), $reason);
        $what = $submission->user->name."'s ".Submissions::describe($submission);
        audit_note("Didn't approve {$what}. Reason: {$reason}");

        return back()->with('status', "Sent back {$what} with your reason.");
    }
}
