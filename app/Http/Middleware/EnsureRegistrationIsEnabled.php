<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks GET and POST /register when self-registration is closed
 * (DISABLE_REGISTRATION=true), regardless of Fortify's own route naming.
 * The route stays registered either way — Wayfinder still generates it and
 * the login page's link is only conditionally hidden, not removed — so a
 * flag flip never requires a frontend rebuild.
 */
class EnsureRegistrationIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.registration_enabled') && $request->is('register')) {
            abort(403, 'Registration is currently closed on this instance.');
        }

        return $next($request);
    }
}
