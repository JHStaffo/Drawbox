<?php

namespace App\Http\Controllers\Protocols;

use App\Http\Controllers\Controller;
use App\Models\Protocol;
use App\Models\ProtocolVersion;
use App\Support\ProtocolLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Medical Director sign-off. Nothing reaches the book without it. */
class ApprovalController extends Controller
{
    public function index()
    {
        return view('protocols.approvals.index', [
            'waiting' => ProtocolVersion::with(['protocol.approved', 'submitter'])->where('status', 'submitted')->orderBy('submitted_at')->get(),
            'recent' => ProtocolVersion::with('reviewer')->whereIn('status', ['approved', 'superseded', 'returned'])->whereNotNull('reviewed_at')->latest('reviewed_at')->limit(25)->get(),
        ]);
    }

    public function show(ProtocolVersion $version)
    {
        $live = $version->protocol->approved;

        return view('protocols.approvals.show', [
            'v' => $version,
            'live' => $live?->id === $version->id ? null : $live,
            'linkFor' => ProtocolLinks::forBook(),
        ]);
    }

    public function approve(Request $request, ProtocolVersion $version)
    {
        $data = $request->validate(
            ['signature' => 'required|string|max:120', 'confirm' => 'accepted'],
            ['signature.required' => 'Type your name to sign.', 'confirm.accepted' => 'Check the box to confirm you reviewed this protocol.'],
        );
        if ($version->status !== 'submitted') {
            return back()->with('error', 'That version isn\'t waiting for approval.');
        }

        audit_note('Approved '.$this->sign($version, $request->user()->id, trim($data['signature'])).'.');

        return redirect()->route('protocols.approvals')->with('status', "Approved {$version->title}. Team Members can see it now.");
    }

    /** Approves several waiting protocols with one signature. */
    public function approveMany(Request $request)
    {
        $data = $request->validate([
            'versions' => 'required|array|min:1',
            'versions.*' => 'integer',
            'signature' => 'required|string|max:120',
            'confirm' => 'accepted',
        ], [
            'versions.required' => 'Tick at least one protocol to approve.',
            'signature.required' => 'Type your name to sign.',
            'confirm.accepted' => 'Check the box to confirm you reviewed every protocol you ticked.',
        ]);
        $versions = ProtocolVersion::with('protocol.approved')->whereIn('id', $data['versions'])->where('status', 'submitted')->orderBy('submitted_at')->get();
        if ($versions->isEmpty()) {
            return back()->with('error', 'None of those are waiting for approval any more.');
        }
        $signature = trim($data['signature']);
        $lines = DB::transaction(fn () => $versions->map(fn (ProtocolVersion $v) => $this->sign($v, $request->user()->id, $signature))->all());
        audit_note('Approved '.count($lines).' protocol(s) at once, signed "'.$signature.'": '.implode('; ', $lines).'.');

        return redirect()->route('protocols.approvals')->with('status', 'Approved '.$versions->count().' '.str('protocol')->plural($versions->count()).'. Team Members can see them now.');
    }

    /** Puts a waiting version in the book. Returns what changed, for the audit log. */
    private function sign(ProtocolVersion $version, int $userId, string $signature): string
    {
        $protocol = $version->protocol;
        $previous = $protocol->approved;
        DB::transaction(function () use ($version, $protocol, $previous, $userId, $signature) {
            $previous?->update(['status' => 'superseded']);
            $version->update([
                'status' => 'approved',
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'signature' => $signature,
                'review_note' => null,
            ]);
            $protocol->update(['approved_version_id' => $version->id]);
        });

        return "{$version->fullTitle()} version {$version->version}, signed \"{$signature}\". In the book from "
            .($previous ? "version {$previous->version}" : 'not in the book').' to version '.$version->version;
    }

    public function sendBack(Request $request, ProtocolVersion $version)
    {
        $data = $request->validate(['review_note' => 'required|string|max:5000'], ['review_note.required' => 'Say what needs to change.']);
        if ($version->status !== 'submitted') {
            return back()->with('error', 'That version isn\'t waiting for approval.');
        }
        $version->update(['status' => 'returned', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => trim($data['review_note'])]);
        audit_note("Sent back {$version->fullTitle()} version {$version->version}. Status from Waiting for approval to Sent back. Note: {$version->review_note}");

        return redirect()->route('protocols.approvals')->with('status', "Sent {$version->title} back to the builders.");
    }

    public function retire(Protocol $protocol)
    {
        $title = $protocol->approved?->fullTitle() ?? 'Protocol #'.$protocol->id;
        if ($protocol->retired_at) {
            $protocol->update(['retired_at' => null]);
            audit_note("Put {$title} back in the book. Retired from yes to no.");

            return back()->with('status', "{$title} is back in the book.");
        }
        $protocol->update(['retired_at' => now()]);
        audit_note("Retired {$title} from the book. Retired from no to yes.");

        return back()->with('status', "Retired {$title}. Team Members no longer see it; its history is kept.");
    }
}
