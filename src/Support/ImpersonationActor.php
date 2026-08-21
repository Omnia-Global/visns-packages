<?php

namespace Visnsstudio\VisnsPackages\Support;

/**
 * Recovers the staff member behind an impersonated request.
 *
 * Auth::user() during an impersonated request resolves to the CLIENT - that is
 * the whole point of the feature - so audit trails written from the request
 * would otherwise credit every action to the client. The acting staff id rides
 * along in the token's name ("{prefix}:{staffUserId}", set by
 * ImpersonationController::issue()), and this is the only way to read it back.
 */
class ImpersonationActor
{
    /**
     * The id of the staff member impersonating, or null when the current
     * request is not an impersonated one.
     */
    public static function id(): ?int
    {
        $user = auth()->user();

        // Session-authenticated requests have no access token at all, and a
        // user model without Sanctum's HasApiTokens has no such method.
        if (! $user || ! method_exists($user, 'currentAccessToken')) {
            return null;
        }

        $token = $user->currentAccessToken();

        $prefix = (string) ModuleConfig::get(
            'impersonation.token_prefix',
            'impersonation-token'
        ) . ':';

        if (! $token || ! str_starts_with((string) $token->name, $prefix)) {
            return null;
        }

        $staffId = substr((string) $token->name, strlen($prefix));

        // Anything that is not a plain id is treated as no attribution rather
        // than a guess: a wrong name in an audit trail is worse than none.
        return is_numeric($staffId) ? (int) $staffId : null;
    }

    /**
     * Is the current request being made under an impersonation token?
     */
    public static function isImpersonating(): bool
    {
        return static::id() !== null;
    }
}
