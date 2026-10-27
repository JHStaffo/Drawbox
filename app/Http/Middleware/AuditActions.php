<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Services\AuditChanges;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs every change someone makes, plus report downloads. The description
 * is the message the page showed them (or the error), unless the
 * controller left a more specific note with audit_note(), followed by
 * what was changed from what (see AuditChanges).
 */
class AuditActions
{
    public function handle(Request $request, Closure $next): Response
    {
        $changes = app(AuditChanges::class);
        $changes->start();
        $response = $next($request);
        $changed = $changes->stop();

        $route = $request->route()?->getName();
        $isChange = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS']);
        $isExport = $route && str_ends_with($request->path(), '.csv');

        if ($response->getStatusCode() === 429) {
            AuditLog::record('login.throttled', 'Too many attempts at '.$request->path().'.', $request->user(), $request->session()->get('login_email') ?? $request->input('email'));

            return $response;
        }

        // Sign-in and sign-out log themselves with more detail.
        if ((! $isChange && ! $isExport) || $request->is('login*') || $route === 'logout') {
            return $response;
        }
        if (! $request->user() && ! $request->attributes->has('audit')) {
            return $response;
        }

        $session = $request->hasSession() ? $request->session() : null;
        $errors = $session?->get('errors')?->all() ?? [];
        $description = $request->attributes->get('audit')
            ?? $session?->get('status')
            ?? ($session?->get('error') ? 'Refused: '.$session->get('error') : null)
            ?? ($errors ? 'Refused: '.implode(' ', $errors) : null)
            ?? ($response->getStatusCode() >= 400 ? 'Refused (error '.$response->getStatusCode().').' : null)
            ?? ($isExport ? 'Downloaded '.$request->path().($request->getQueryString() ? '?'.urldecode($request->getQueryString()) : '').'.' : 'Done.');

        // Spell out what was changed from what, when the action saved anything worth listing.
        if ($changed && ! str_starts_with($description, 'Refused')) {
            $description .= ' Changed '.$changed;
        }

        AuditLog::record($route ?? $request->method().' '.$request->path(), $description, $request->user());

        return $response;
    }
}
