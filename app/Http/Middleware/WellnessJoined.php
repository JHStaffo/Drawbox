<?php

namespace App\Http\Middleware;

use App\Wellness\Wellness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wellness features open only after the Team Member has signed up and
 * agreed to the rules. Until then the web sends them to the sign-up page
 * and the API answers 409 not_joined.
 */
class WellnessJoined
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(Wellness::class)->participant($request->user())?->isActive()) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Sign up for Wellness and agree to the rules first.', 'code' => 'not_joined'], 409);
            }

            return redirect()->route('wellness');
        }

        return $next($request);
    }
}
