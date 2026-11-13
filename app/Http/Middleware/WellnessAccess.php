<?php

namespace App\Http\Middleware;

use App\Wellness\Wellness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Lets in Team Members whose profile has Wellness, or who were added one by one as testers. */
class WellnessAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() && Wellness::canUse($request->user()), 403);

        return $next($request);
    }
}
