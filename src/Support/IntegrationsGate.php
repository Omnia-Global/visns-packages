<?php

namespace Visnsstudio\VisnsPackages\Support;

/**
 * Who may manage integrations: connect one, finish its OAuth leg, test it,
 * sync it or disconnect it.
 *
 * One copy for IntegrationsController and OAuthController. The OAuth routes
 * used to be reachable by a guest, and their state was not tied to anybody,
 * so an outsider could run the whole consent leg with their own account and
 * replace the organisation's connection (security review 2026-09-27).
 *
 * `visns-packages.integrations_permission` names the permission (default
 * "manage integrations"); an empty value switches the check off for an app
 * that gates the routes itself.
 */
final class IntegrationsGate
{
    public static function authorize(): void
    {
        $permission = config('visns-packages.integrations_permission', 'manage integrations');

        if (!$permission) {
            return;
        }

        $user = request()->user();

        if (!$user) {
            abort(403);
        }

        // Spatie's `can` when the app uses it; a plain gate otherwise.
        if (method_exists($user, 'can') && $user->can($permission)) {
            return;
        }

        abort(403, 'You do not have permission to manage integrations.');
    }
}
