<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EmsAffiliation;
use App\Models\EmsCheck;
use App\Models\EmsCheckResult;
use App\Models\EmsUnacceptableAgency;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ems\Affiliations;
use App\Services\Ems\Agency;
use App\Services\Ems\ProviderSearch;
use App\Services\Ems\SearchFailed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Admin > EMS Affiliations: every active Team Member searched on the Virginia
 * Office of EMS provider search, by button or once a month, with every check
 * kept; other agencies they're listed with go to a review queue.
 */
class EmsAffiliationController extends Controller
{
    public function index()
    {
        return view('admin.ems.index', [
            'checks' => EmsCheck::with('runner')->latest('id')->paginate(24),
            'active' => EmsCheck::whereIn('status', ['queued', 'running'])->latest('id')->first(),
            'queue' => EmsAffiliation::with('user')->where('status', 'pending')->whereNull('gone_at')
                ->whereHas('user', fn ($q) => $q->where('active', true))->orderBy('first_seen_at')->get(),
            'flagged' => EmsAffiliation::with('user')->where('status', 'unacceptable')->whereNull('gone_at')
                ->whereHas('user', fn ($q) => $q->where('active', true))->orderBy('agency')->get(),
            'foundExclusions' => User::where('active', true)->whereNotNull('ems_exclusion_at')->whereNotNull('ems_exclusion_found_at')->orderBy('name')->get(),
            'unacceptable' => EmsUnacceptableAgency::orderBy('name')->get(),
            'ours' => Setting::get('ems_our_agency'),
            'seenAgencies' => EmsAffiliation::distinct()->orderBy('agency')->pluck('agency'),
        ]);
    }

    public function store(Request $request, Affiliations $affiliations)
    {
        if (EmsCheck::whereIn('status', ['queued', 'running'])->exists()) {
            return back()->with('error', 'A check is already running.');
        }
        $check = $affiliations->queue($request->user());

        return redirect()->route('admin.ems')->with('status', "Started a check of {$check->members_total} active Team Members. It starts within a minute and takes a few seconds per Team Member.");
    }

    public function show(EmsCheck $check)
    {
        $results = $check->results()->with('user')->get()->sortBy(fn (EmsCheckResult $r) => [$r->rank(), $r->id])->values();
        $links = EmsAffiliation::whereIn('user_id', $results->pluck('user_id')->filter())->get()->groupBy('user_id');

        return view('admin.ems.show', ['check' => $check, 'results' => $results, 'links' => $links, 'ours' => Setting::get('ems_our_agency')]);
    }

    /** Search one name or certification # and show what came back, without saving anything. */
    public function test(Request $request, ProviderSearch $search)
    {
        $data = $request->validate([
            'first' => 'nullable|string|max:60',
            'last' => 'nullable|string|max:60|required_without:cert',
            'cert' => 'nullable|string|max:30',
        ], ['last.required_without' => 'Type a last name or a certification #.']);
        $what = $data['cert'] ?? null ? "certification # {$data['cert']}" : trim(($data['first'] ?? '').' '.$data['last']);
        try {
            $providers = $search->search($data['first'] ?? null, $data['last'] ?? null, $data['cert'] ?? null);
        } catch (SearchFailed $e) {
            $this->keepLast($search);
            audit_note("Tried the provider search for {$what}: {$e->getMessage()}");

            return back()->withInput()->with('error', $e->getMessage())->with('ems_test', ['what' => $what, 'providers' => null, 'trace' => $search->trace]);
        }
        $this->keepLast($search);
        audit_note("Tried the provider search for {$what}: ".count($providers).' found.');

        return back()->withInput()->with('ems_test', ['what' => $what, 'providers' => $providers, 'trace' => $search->trace]);
    }

    /** Download what the site sent on the last Try the search (public pages), to see what changed. */
    public function last(Request $request, string $which)
    {
        $path = "ems/last-{$which}.html";
        abort_unless(in_array($which, ['page', 'result'], true) && Storage::disk('local')->exists($path), 404);
        AuditLog::record('admin.ems.last', 'Downloaded the provider search '.($which === 'page' ? 'page' : 'results').' from the last Try the search.', $request->user());

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"provider-search-{$which}.html.txt\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function keepLast(ProviderSearch $search): void
    {
        Storage::disk('local')->put('ems/last-page.html', $search->lastPage);
        Storage::disk('local')->put('ems/last-result.html', $search->lastResult);
    }

    public function agency(Request $request)
    {
        $data = $request->validate(['agency' => 'required|string|max:150']);
        Setting::put('ems_our_agency', trim($data['agency']));

        return back()->with('status', 'Saved our agency name.');
    }

    public function review(Request $request, EmsAffiliation $affiliation)
    {
        $data = $request->validate([
            'status' => 'required|in:acceptable,unacceptable,pending',
            'note' => 'nullable|string|max:1000',
            'everyone' => 'nullable|boolean',
        ]);
        $from = $affiliation->pill()[0];
        $affiliation->update([
            'status' => $data['status'],
            'auto_flagged' => false,
            'review_note' => $data['note'] ?? null,
            'reviewed_by' => $data['status'] === 'pending' ? null : $request->user()->id,
            'reviewed_at' => $data['status'] === 'pending' ? null : now(),
        ]);
        $to = $affiliation->pill()[0];
        $note = "Reviewed {$affiliation->user?->name}'s affiliation with {$affiliation->agency}: from {$from} to {$to}".(($data['note'] ?? '') !== '' ? ". Note: {$data['note']}" : '').'.';

        if (($data['everyone'] ?? false) && $data['status'] === 'unacceptable') {
            $flagged = $this->addUnacceptable($affiliation->agency, $data['note'] ?? null, $request->user());
            $note .= " Added {$affiliation->agency} to the unacceptable agencies list".($flagged ? " and flagged {$flagged} other ".str('affiliation')->plural($flagged) : '').'.';
        }
        audit_note($note);

        return back()->with('status', "Marked {$affiliation->agency} {$to} for {$affiliation->user?->name}.");
    }

    public function unacceptableStore(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'note' => 'nullable|string|max:1000']);
        if (Agency::key($data['name']) === '') {
            return back()->withErrors(['name' => 'Type an agency name.']);
        }
        if (EmsUnacceptableAgency::where('agency_key', Agency::key($data['name']))->exists()) {
            return back()->withErrors(['name' => 'That agency is already on the list.']);
        }
        if (Agency::key($data['name']) === Agency::key(Setting::get('ems_our_agency'))) {
            return back()->withErrors(['name' => "That's our agency."]);
        }
        $flagged = $this->addUnacceptable(trim($data['name']), $data['note'] ?? null, $request->user());
        audit_note("Added {$data['name']} to the unacceptable agencies list".(($data['note'] ?? '') !== '' ? " (note: {$data['note']})" : '').($flagged ? "; flagged {$flagged} waiting ".str('affiliation')->plural($flagged) : '').'.');

        return back()->with('status', "Added {$data['name']} to the unacceptable agencies.".($flagged ? " {$flagged} waiting ".str('affiliation')->plural($flagged).' marked unacceptable.' : ''));
    }

    public function unacceptableDestroy(EmsUnacceptableAgency $agency)
    {
        $agency->delete();
        // Flagged only because of the list: back to the review queue.
        $back = EmsAffiliation::where('agency_key', $agency->agency_key)->where('status', 'unacceptable')->where('auto_flagged', true)
            ->update(['status' => 'pending', 'auto_flagged' => false]);
        audit_note("Removed {$agency->name} from the unacceptable agencies list".($back ? "; {$back} ".str('affiliation')->plural($back).' went back to review' : '').'.');

        return back()->with('status', "Removed {$agency->name} from the unacceptable agencies.");
    }

    public function member(User $user)
    {
        return view('admin.ems.member', [
            'member' => $user->load('emsExclusionBy'),
            'affiliations' => EmsAffiliation::with('reviewer')->where('user_id', $user->id)->orderByRaw('gone_at is not null')->orderBy('agency')->get(),
            'results' => EmsCheckResult::with('check')->where('user_id', $user->id)->latest('id')->limit(36)->get(),
            'ours' => Setting::get('ems_our_agency'),
        ]);
    }

    public function memberCert(Request $request, User $user)
    {
        $data = $request->validate(['cert' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/']], ['cert.regex' => 'Letters and numbers only.']);
        $cert = ($data['cert'] ?? '') !== '' ? strtoupper($data['cert']) : null;
        $from = $user->ems_cert;
        $user->forceFill(['ems_cert' => $cert])->save();
        audit_note("Changed {$user->name}'s Virginia EMS certification # from ".($from ?? 'none').' to '.($cert ?? 'none (search by name)').'.');

        return back()->with('status', $cert ? "Saved {$user->name}'s certification #." : "{$user->name} will be searched by name.");
    }

    public function memberExclusion(Request $request, User $user)
    {
        $data = $request->validate(['excluded' => 'required|boolean', 'note' => 'nullable|string|max:1000']);
        if ($data['excluded']) {
            if (trim($data['note'] ?? '') === '') {
                return back()->withErrors(['note' => 'Say why they are not on the provider search.']);
            }
            $user->forceFill(['ems_exclusion_at' => $user->ems_exclusion_at ?? now(), 'ems_exclusion_by' => $request->user()->id, 'ems_exclusion_note' => $data['note'], 'ems_exclusion_found_at' => null])->save();
            audit_note("Marked {$user->name} accepted exclusion on the Virginia EMS provider search. Note: {$data['note']}");

            return back()->with('status', "{$user->name} is an accepted exclusion. They're still searched every time.");
        }
        $note = $user->ems_exclusion_note;
        $user->forceFill(['ems_exclusion_at' => null, 'ems_exclusion_by' => null, 'ems_exclusion_note' => null, 'ems_exclusion_found_at' => null])->save();
        audit_note("Removed {$user->name}'s accepted exclusion on the Virginia EMS provider search (was: {$note}).");

        return back()->with('status', "{$user->name} is no longer an accepted exclusion.");
    }

    /** Add to the list and flag every waiting affiliation with that agency. Returns how many were flagged. */
    private function addUnacceptable(string $name, ?string $note, User $by): int
    {
        $key = Agency::key($name);
        EmsUnacceptableAgency::firstOrCreate(['agency_key' => $key], ['name' => $name, 'note' => $note, 'added_by' => $by->id]);

        return EmsAffiliation::where('agency_key', $key)->where('status', 'pending')->update(['status' => 'unacceptable', 'auto_flagged' => true]);
    }
}
