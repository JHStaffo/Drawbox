<?php

namespace App\Http\Middleware;

use App\Wellness\Wellness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hides every Wellness page and API route while the module is turned off. */
class WellnessEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Wellness::enabled(), 404);

        return $next($request);
    }
}
