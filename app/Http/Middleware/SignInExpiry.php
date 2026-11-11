<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A sign-in is good for up to 12 hours, then the team member signs in again with a new code. */
class SignInExpiry
{
    public const HOURS = 12;

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $since = $request->session()->get('signed_in_at');
            if ($since === null) {
                // Signed in before this rule existed: start the 12 hours now.
                $request->session()->put('signed_in_at', now()->timestamp);
            } elseif (now()->timestamp - $since >= self::HOURS * 3600) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/login')->with('status', 'Your sign-in expired after '.self::HOURS.' hours. Sign in again to keep going.');
            }
        }

        return $next($request);
    }
}
