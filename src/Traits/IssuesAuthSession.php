<?php

namespace Visnsstudio\VisnsPackages\Traits;

use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The three things every sign-in path in this package does once the account is
 * established, regardless of what proved it.
 *
 * Extracted from AuthController when a second entry point appeared - the
 * passkey ceremony, which arrives at an authenticated user without ever seeing
 * a password. The two controllers answer with the same envelope and run the
 * same hooks; a second copy of either would be a second thing to keep in step,
 * and the front end branches on that envelope.
 */
trait IssuesAuthSession
{
    /**
     * The session's live CSRF token, returned with every stateful auth response.
     *
     * This is the contract, and it exists because the framework rotates the
     * session out from under the page that called us.
     *
     * Auth::login() -> updateSession() rotates the session, and WHAT it rotates
     * depends on the framework version:
     *
     *   Laravel 11 and earlier   session->migrate(true)     id only
     *   Laravel 12 and later     session->regenerate(true)  id AND CSRF token
     *
     * That change is deliberate framework security - a privilege change should
     * not leave the old CSRF token valid - and this package does not fight it,
     * on either version. It also means a single-page app that shows the 2FA
     * prompt without reloading is left holding a stale <meta csrf-token>: on
     * Laravel 12 its next POST gets 419 while its GETs carry on working, which
     * is exactly how the bug was first reported.
     *
     * So the fix is not to suppress the rotation, it is to tell the caller: every
     * successful login response - plain, requires_two_factor, and the challenge
     * completion - carries the post-handling token here, and the frontend
     * resyncs its meta tag from it. That works identically whether or not the
     * framework rotated anything, which is what makes it safe across versions.
     *
     * Nothing is disclosed: the token is already rendered into the page being
     * answered.
     *
     * (logout() is the one place that still rotates the token explicitly, via
     * regenerateToken() - there a fresh token is the entire point.)
     */
    protected function csrfToken(Request $request): ?string
    {
        return $request->hasSession() ? $request->session()->token() : null;
    }

    /**
     * Fire the post-login hooks (last-login stamps, IP columns, token cleanup).
     * A hook is a side effect, never a veto: by this point the user is in.
     */
    protected function runPostLoginHooks($user, Request $request): void
    {
        foreach (ModuleConfig::callables('auth.post_login_hooks') as $hook) {
            $hook($user, $request);
        }
    }

    /**
     * The `previous` value echoed back to the front end. '/' and '/login' are
     * blanked so a post-login redirect cannot bounce the user back to the login
     * screen; an application that routes its own redirects turns this off.
     *
     * @param  mixed  $location
     * @return mixed
     */
    protected function filteredPrevious($location)
    {
        if (! ModuleConfig::get('auth.filter_previous', true)) {
            return $location;
        }

        return $location == '/' || $location == '/login' ? '' : $location;
    }
}
