<?php

namespace Visnsstudio\VisnsPackages\Middleware;

use Closure;
use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Re-ask for the password before a secret leaves the server.
 *
 * The session stamp this reads is written by the vault's confirm-password
 * endpoint and nothing else. Its point is the borrowed-laptop case: an
 * unattended session already carries the access permission, so the only thing
 * standing between it and every stored password is a check the session itself
 * cannot satisfy.
 *
 * A refusal is **423 Locked**, not 401 or 403, and carries a machine-readable
 * `reason`. Both matter to the caller: 401 would send the browser's own auth
 * handling - and most SPA interceptors - into a logout it does not need, and a
 * front end has to be able to tell "confirm and retry" apart from "you cannot do
 * this at all" without matching on a message string.
 *
 * Registered as `vault.confirmed`.
 */
class EnsureVaultPasswordConfirmed
{
    public function handle(Request $request, Closure $next)
    {
        $ttl = (int) ModuleConfig::get('vault.confirmation_ttl_minutes', 10);

        $confirmedAt = $request->session()->get('visns.vault.confirmed_at');

        if ($this->stillValid($confirmedAt, $ttl)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Confirm your password to continue.',
            'reason' => 'password_confirmation_required',
            'ttl_minutes' => $ttl,
        ], 423);
    }

    /**
     * The stamp is a Unix timestamp. Anything else - a stale session written by
     * an older release, a value somebody put there by hand - fails closed.
     */
    private function stillValid($confirmedAt, int $ttl): bool
    {
        if (! is_numeric($confirmedAt)) {
            return false;
        }

        if ($ttl <= 0) {
            return false;
        }

        $age = now()->getTimestamp() - (int) $confirmedAt;

        // A negative age means the clock moved backwards, or the stamp is from
        // the future; neither is a confirmation this request made.
        return $age >= 0 && $age < $ttl * 60;
    }
}
