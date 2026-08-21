<?php

namespace Visnsstudio\VisnsPackages\Contracts;

use Illuminate\Http\Request;

/**
 * Turns the identifier typed on the login form into a user account.
 *
 * The package's own rule - email-shaped identifiers match on `email`, anything
 * else on `username` - is only one of many. Applications whose accounts live
 * behind a client record, a tenant scope or an external directory register their
 * own implementation in config('visns-packages.auth.user_resolver') rather than
 * forking the controller.
 *
 * Returning null means "no such account"; the controller then answers with the
 * generic login-failed message, so a resolver must not throw or leak which
 * identifiers exist.
 */
interface UserResolver
{
    /**
     * @param  string   $identifier  Whatever the login form posted.
     * @param  Request  $request     The login request, for tenant/host scoping.
     * @return object|null           The user model, or null when none matches.
     */
    public function __invoke(string $identifier, Request $request): ?object;
}
