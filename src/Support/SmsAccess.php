<?php

namespace Visnsstudio\VisnsPackages\Support;

use Visnsstudio\VisnsPackages\Models\SmsLine;

/**
 * Who may see what in the messaging module.
 *
 * One class rather than a private method on each of the three controllers,
 * because the answer has to be identical in all of them: a line you are not
 * attached to does not exist, and neither do its threads, its messages or its
 * unread count. Three copies of that rule would eventually become two rules.
 */
class SmsAccess
{
    /**
     * Does this user hold the administrative grant?
     *
     * A permission name that has never been seeded makes Spatie throw; that is a
     * deployment gap, not an authorisation, so it fails closed. A permission
     * configured as null is a deliberate "do not gate this here" and passes -
     * which for `manage` means everyone with access administers, so do that only
     * if something else is enforcing it.
     */
    public static function manages($user): bool
    {
        $permission = ModuleConfig::get('messaging.permissions.manage', 'Messaging Manage');

        if (! is_string($permission) || $permission === '') {
            return true;
        }

        if ($user === null) {
            return false;
        }

        try {
            return (bool) $user->hasPermissionTo($permission);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The ids of the lines this user may work.
     *
     * @return array<int, int>
     */
    public static function lineIds($user): array
    {
        return SmsLine::query()
            ->visibleTo($user, self::manages($user))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
