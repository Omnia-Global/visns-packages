<?php

namespace Visnsstudio\VisnsPackages\Support;

/**
 * The whitelisted user payload for endpoints that must not serialize a whole
 * user model.
 *
 * Both callers are token-only endpoints whose token travels in a URL: the
 * impersonation validator and the OTP login's `minimal_response` mode. Handing
 * back the model would ship whatever the application happens to keep on it -
 * live OTP hashes, portal documents, addresses - to anyone holding the token.
 * So the payload is built by naming fields, never by removing them.
 */
class MinimalUserPayload
{
    /**
     * @param  object      $user       The user model.
     * @param  string|null $moduleKey  Module whose `minimal_user` block to use
     *                                 ('otp', 'impersonation'); falls back to
     *                                 auth.minimal_user when the module leaves
     *                                 it null.
     */
    public static function build(object $user, ?string $moduleKey = null): array
    {
        $config = $moduleKey === null
            ? null
            : ModuleConfig::get($moduleKey . '.minimal_user');

        if (! is_array($config)) {
            $config = ModuleConfig::get('auth.minimal_user', []);
        }

        $payload = [];

        foreach ((array) ($config['fields'] ?? []) as $field) {
            $payload[$field] = $user->{$field} ?? null;
        }

        foreach ((array) ($config['relations'] ?? []) as $relation => $spec) {
            $related = $user->{$relation} ?? null;

            if (! is_object($related)) {
                $payload[$relation] = null;

                continue;
            }

            $block = [];

            foreach ((array) ($spec['fields'] ?? []) as $field) {
                $block[$field] = $related->{$field} ?? null;
            }

            $payload[$relation] = $block;
        }

        return $payload;
    }
}
