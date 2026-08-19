<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Auth;

use Illuminate\Http\Request;

/**
 * Stands in for the CRM's inactive-client gate: a gate owns its own status code
 * and body, and the login flow returns it untouched.
 */
class RejectingGate
{
    public function __invoke($user, Request $request)
    {
        return response()->json(
            ['error' => 'Your account is currently inactive.'],
            403
        );
    }
}
