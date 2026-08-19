<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Auth;

use Illuminate\Http\Request;

/**
 * Stands in for the CRM's reset link: one host, and a `portal` flag choosing
 * between a query-string token and a path segment.
 */
class PortalResetUrlBuilder
{
    public function __invoke($user, string $token, Request $request): string
    {
        $base = rtrim((string) config('portal.url'), '/') . '/verify/';

        return $request->input('portal') === 'true'
            ? $base . '?code=' . $token
            : $base . $token;
    }
}
