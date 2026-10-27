<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Setting;
use App\Models\User;
use App\Services\Credits;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DispatchController extends Controller
{
    public function create()
    {
        return view('dispatch.create', [
            'employees' => User::byLastName(User::where('active', true)->get()),
            'recent' => Call::with('credits.user', 'credits.draw')->latest()->limit(10)->get(),
            'windowDays' => Setting::get('draw_window_days'),
        ]);
    }

    public function store(Request $request, Credits $credits)
    {
        $data = $request->validate([
            'occurred_at' => 'required|date|before_or_equal:now',
            'location' => 'required|string|max:255',
            'unit' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
            'crew' => 'required|array|min:1',
            'crew.*' => ['integer', Rule::exists('users', 'id')->where('active', true)],
        ], ['crew.required' => 'Pick at least one crew member.']);

        $call = $credits->creditCall(
            collect($data)->only(['occurred_at', 'location', 'unit', 'notes'])->all(),
            $data['crew'],
            $request->user(),
        );

        $call->load('credits.user');
        audit_note('Credited '.$call->credits->pluck('user.name')->join(', ').' for the call at '.$call->location.($call->unit ? " ({$call->unit})" : '').', '.$call->occurred_at->format('M j g:i A').'.');

        return redirect()->route('dispatch.create')
            ->with('status', 'Credited '.$call->credits()->count().' crew member(s) for the call at '.$call->location.'. They\'ll get an email with the next batch, sent every 2 hours.');
    }

    /** Take back a call credited by mistake, as long as nobody has drawn from it yet. */
    public function destroy(Call $call)
    {
        $drawn = $call->credits()->whereHas('draw')->with('user')->get();
        if ($drawn->isNotEmpty()) {
            return back()->withErrors(['undo' => $drawn->pluck('user.name')->join(', ').' already drew from this call, so it can\'t be undone. A supervisor can remove that draw from the dashboard.']);
        }

        $call->delete();

        return redirect()->route('dispatch.create')
            ->with('status', 'Undid the credit for the call at '.$call->location.'.');
    }
}
