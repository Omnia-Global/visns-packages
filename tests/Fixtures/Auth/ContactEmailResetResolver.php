<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Stands in for the CRM's reset lookup: an address that is not a login address
 * still has to find the account behind it.
 *
 * Here the indirection is deliberately crude - a contact address of
 * "contact+<username>@example.test" resolves to the account with that username -
 * so a test can prove the resolver ran without needing a second table.
 */
class ContactEmailResetResolver
{
    public function __invoke(string $email, Request $request): ?object
    {
        if ($user = User::where('email', $email)->first()) {
            return $user;
        }

        if (preg_match('/^contact\+(.+)@example\.test$/', $email, $matches)) {
            return User::where('username', $matches[1])->first();
        }

        return null;
    }
}
