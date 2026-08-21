<?php

namespace App\Http\Middleware;

use App\Models\TeamInvitation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks GET and POST /register when self-registration is closed
 * (DISABLE_REGISTRATION=true), regardless of Fortify's own route naming.
 * The route stays registered either way — Wayfinder still generates it and
 * the login page's link is only conditionally hidden, not removed — so a
 * flag flip never requires a frontend rebuild.
 *
 * One exception: someone holding a valid, still-pending team invitation
 * (TeamInvitationController::show stashes its token in the session on
 * visit) is let through even while registration is otherwise closed —
 * "invite-only" and "self-registration is closed" are different things,
 * and without this an invited person with no existing account had no way
 * in at all. AcceptPendingTeamInvitationOnLogin completes the join once
 * they register; this middleware only decides whether /register itself
 * is reachable.
 */
class EnsureRegistrationIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.registration_enabled') || ! $request->is('register')) {
            return $next($request);
        }

        if ($this->hasValidPendingInvitation($request)) {
            return $next($request);
        }

        abort(403, 'Registration is currently closed on this instance.');
    }

    private function hasValidPendingInvitation(Request $request): bool
    {
        $token = $request->session()->get('pending_invitation_token');

        if (! is_string($token)) {
            return false;
        }

        $invitation = TeamInvitation::where('token', $token)->first();

        return $invitation !== null && ! $invitation->isAccepted() && ! $invitation->isExpired();
    }
}
