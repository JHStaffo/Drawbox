<?php

namespace App\Wellness\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Wellness\Progress;
use App\Wellness\Wellness;
use Illuminate\Http\Request;

/**
 * Devices and sync keys. A sync key is an API token that can only send
 * wellness readings, for the Apple Health Shortcut on someone's iPhone.
 */
class ConnectController extends Controller
{
    public const IMPORT_ABILITY = 'wellness:import';

    public function index(Request $request, Wellness $wellness, Progress $progress)
    {
        $user = $request->user();
        if (! $wellness->participant($user)?->isActive()) {
            return redirect()->route('wellness');
        }

        return view('wellness.connect', [
            'tokens' => $user->tokens()->latest('id')->get(),
            'newToken' => session('new_token'),
            'lastSync' => $progress->lastSync($user),
        ]);
    }

    public function store(Request $request, Wellness $wellness)
    {
        $user = $request->user();
        abort_unless($wellness->participant($user)?->isActive(), 403);
        $name = trim($request->validate(['name' => 'nullable|string|max:60'])['name'] ?? '') ?: 'Apple Health Shortcut';
        if ($user->tokens()->count() >= 10) {
            return back()->with('error', 'You already have 10 keys. Remove one you no longer use first.');
        }
        $token = $user->createToken($name, [self::IMPORT_ABILITY]);
        audit_note("Made a wellness sync key named \"{$name}\".");

        return redirect()->route('wellness.connect')->with('new_token', $token->plainTextToken)
            ->with('status', 'Your sync key is below. Copy it now: it is only shown once.');
    }

    public function destroy(Request $request, int $token)
    {
        $found = $request->user()->tokens()->whereKey($token)->firstOrFail();
        $found->delete();
        audit_note("Removed the key \"{$found->name}\". Anything using it can no longer send or read data.");

        return redirect()->route('wellness.connect')->with('status', "Removed \"{$found->name}\".");
    }
}
