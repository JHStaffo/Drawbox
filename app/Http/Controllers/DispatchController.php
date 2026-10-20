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
            'employees' => User::where('active', true)->orderBy('name')->get(),
            'recent' => Call::with('credits.user')->latest()->limit(10)->get(),
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

        return redirect()->route('dispatch.create')
            ->with('status', 'Credited '.$call->credits()->count().' crew member(s) for the call at '.$call->location.'.');
    }
}
