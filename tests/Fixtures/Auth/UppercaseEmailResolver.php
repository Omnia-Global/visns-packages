<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Contracts\UserResolver;

/**
 * A resolver the built-in one could not produce, so a test can tell the two
 * apart: it upper-cases the identifier before looking it up.
 */
class UppercaseEmailResolver implements UserResolver
{
    public function __invoke(string $identifier, Request $request): ?object
    {
        return User::where('email', strtoupper($identifier))->first();
    }
}
