<?php

namespace Visnsstudio\VisnsPackages\Auth;

use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Contracts\UserResolver;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The lookup the auth controller has always performed, lifted out of the
 * controller so an application can replace it without replacing the login flow.
 *
 * An identifier that validates as an email address is looked up on `email`,
 * everything else on `username`. That split is what makes a single login field
 * accept both.
 */
class DefaultUserResolver implements UserResolver
{
    public function __invoke(string $identifier, Request $request): ?object
    {
        // An absent login field used to arrive here as null and match nothing in
        // SQL; as a string it would match a row with an empty username, so it is
        // rejected up front.
        if (trim($identifier) === '') {
            return null;
        }

        $column = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false
            ? 'email'
            : 'username';

        return ModuleConfig::userQuery('auth')
            ->where($column, $identifier)
            ->first();
    }
}
