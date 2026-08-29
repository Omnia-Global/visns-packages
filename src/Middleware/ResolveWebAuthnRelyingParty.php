<?php

namespace Visnsstudio\VisnsPackages\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Point the WebAuthn relying party at the host actually being browsed.
 *
 * A passkey is bound to a domain, and both halves of a ceremony have to name
 * the same one: the browser is told the relying party ID when a credential is
 * created, and laragear/webauthn checks the assertion's origin against it on
 * the way back. Left unconfigured the library falls back to the host of
 * APP_URL, which on a developer machine is localhost while the browser is on
 * some other origin - every ceremony then fails on "Response origin not
 * allowed for this app".
 *
 * Deriving it from the request instead means one deployment works on whatever
 * host it is served from, with no per-environment variable to keep in step.
 * WEBAUTHN_ID (config/webauthn.php's `relying_party.id`) still wins when set,
 * for the case where several hosts must share one passkey - pin it to the
 * parent domain.
 *
 * Applied per request rather than once at boot because config survives between
 * requests under Octane, and a worker that had served one host would otherwise
 * keep answering with it.
 *
 * Registered by the package service provider under the alias `webauthn.rp`,
 * unless the application has already claimed that name.
 */
class ResolveWebAuthnRelyingParty
{
    public function handle(Request $request, Closure $next)
    {
        if (blank(config('webauthn.relying_party.id'))) {
            // getHost() is the hostname without the port - which is what a
            // relying party ID is. Ports are part of the origin, not the RP ID.
            config(['webauthn.relying_party.id' => $request->getHost()]);
        }

        return $next($request);
    }
}
