<?php

namespace Visnsstudio\VisnsPackages\Tests\Fixtures\Auth;

use App\Models\User;

/**
 * A user model whose remember token lives in a column that does not exist.
 *
 * Stands in for an application that has not run the standard Laravel migration:
 * without the graceful degrade, Auth::login($user, true) would try to persist a
 * token into a missing column and turn a missing migration into a login outage.
 */
class NoRememberColumnUser extends User
{
    protected $table = 'users';

    public function getRememberTokenName()
    {
        return 'column_that_does_not_exist';
    }
}
