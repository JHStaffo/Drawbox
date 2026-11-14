<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OigCheck;
use App\Models\OigCheckResult;
use App\Services\OigExclusions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Admin > OIG Exclusions: every active Team Member checked against the OIG's
 * exclusion list, by button or once a month, with every check kept.
 */
class OigController extends Controller
{
    public function index()
    {
        return view('admin.oig.index', ['checks' => OigCheck::with('runner')->latest('id')->paginate(24)]);
    }

    public function store(Request $request, OigExclusions $oig)
    {
        @set_time_limit(300);
        $check = $oig->run($request->user());
        if ($check->status === 'failed') {
            return back()->with('error', $check->error);
        }
        $found = $check->excluded_count ? "{$check->excluded_count} exclusion(s) found" : '0 exclusions found';
        $review = $check->review_count ? ", {$check->review_count} to check by hand" : '';

        return redirect()->route('admin.oig.show', $check)->with('status', "Checked {$check->members_checked} Team Members: {$found}{$review}.");
    }

    public function show(OigCheck $check)
    {
        $results = $check->results()->with('reviewer')->get()
            ->sortBy(fn (OigCheckResult $r) => [$r->isExcluded() ? 0 : ($r->needsReview() ? 1 : 2), $r->id])->values();

        return view('admin.oig.show', ['check' => $check, 'results' => $results]);
    }

    /**
     * Record a check done by hand on the OIG website (search the name, then
     * Verify with the SSN): the outcome, how it was checked, and optionally a
     * copy of the OIG verification page, stored encrypted.
     */
    public function review(Request $request, OigCheckResult $result)
    {
        $data = $request->validate([
            'outcome' => 'required|in:excluded,not_excluded',
            'note' => 'required|string|max:2000',
            'file' => 'nullable|file|mimes:pdf,png,jpg,jpeg|max:10240',
        ], ['note.required' => 'Say how it was checked.']);
        if (preg_match('/\d{3}[\s.-]?\d{2}[\s.-]?\d{4}/', $data['note'])) {
            return back()->withErrors(['note' => 'Leave the SSN out of the note.']);
        }

        $update = [
            'review_outcome' => $data['outcome'],
            'review_note' => $data['note'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ];
        if ($file = $request->file('file')) {
            if ($result->review_file) {
                Storage::disk('local')->delete($result->review_file);
            }
            $path = "oig/{$result->oig_check_id}/".$result->id.'-'.bin2hex(random_bytes(8)).'.bin';
            Storage::disk('local')->put($path, Crypt::encrypt(file_get_contents($file->getRealPath())));
            $update += ['review_file' => $path, 'review_file_name' => mb_substr($file->getClientOriginalName(), 0, 200)];
        }
        $before = $result->review_outcome;
        $result->update($update);
        $result->check->recount();

        $outcome = $data['outcome'] === 'excluded' ? 'Excluded' : 'Not excluded';
        audit_note(($before ? 'Changed the manual OIG check' : 'Recorded a manual OIG check')." for {$result->name} on the {$result->check->created_at->format('M j, Y')} check: {$outcome}. Note: {$data['note']}".($file ? ' (verification file attached)' : ''));

        return back()->with('status', "Saved the manual check for {$result->name}.");
    }

    public function file(Request $request, OigCheckResult $result)
    {
        abort_unless($result->review_file && Storage::disk('local')->exists($result->review_file), 404);
        AuditLog::record('admin.oig.file', "Opened the OIG verification file for {$result->name}.", $request->user());

        return response(Crypt::decrypt(Storage::disk('local')->get($result->review_file)), 200, [
            'Content-Type' => match (strtolower(pathinfo($result->review_file_name, PATHINFO_EXTENSION))) {
                'pdf' => 'application/pdf', 'png' => 'image/png', default => 'image/jpeg',
            },
            'Content-Disposition' => 'inline; filename="'.addslashes($result->review_file_name).'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
