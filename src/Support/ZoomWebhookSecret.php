<?php

namespace Visnsstudio\VisnsPackages\Support;

use Visnsstudio\VisnsPackages\Services\IntegrationRegistry;

/**
 * The one place Zoom's webhook signing secret is resolved.
 *
 * Both the signature middleware and the URL-validation answer need it, and
 * they MUST agree: a middleware that accepts a delivery whose validation
 * answer was signed with a different secret fails Zoom's endpoint check in a
 * way no log line explains.
 *
 * Resolution mirrors ZoomApiClient's credentials: the `zoom` integration
 * setting (the settings UI's row, then its declared env var), falling back to
 * the pre-integrations `call_queue.webhook_secret_token` config key.
 */
class ZoomWebhookSecret
{
    public static function resolve(): ?string
    {
        $registry = app(IntegrationRegistry::class);

        if ($registry->exists('zoom')) {
            return $registry->credential(
                'zoom',
                'webhook_secret',
                ModuleConfig::get('call_queue.webhook_secret_token')
            );
        }

        return ModuleConfig::get('call_queue.webhook_secret_token');
    }

    /**
     * Every secret a delivery may be signed with, distinct and non-empty, the
     * call queue's own first.
     *
     * More than one since 4.19.0, because more than one Zoom app now posts to
     * the same endpoint. The Server-to-Server app's subscription carries the
     * call queue and the house's SMS events; the user-level SMS app (`zoom_sms`,
     * the one a client authorises from inside their own tenant) carries the
     * `phone.sms_*` events for every client line, signed with THAT app's secret
     * token. So:
     *
     *   1. resolve()                                  - unchanged, and first
     *   2. the `zoom_sms` integration's `webhook_secret`, when the host
     *      application declares such an integration AND such a field
     *   3. `messaging.zoom.webhook_secrets`           - any others, from config
     *
     * The middleware accepts a delivery signed with any of them and remembers
     * which, so the URL-validation answer is signed with the secret of the app
     * that asked - see ZoomWebhookController::urlValidation().
     *
     * @return array<int, string>
     */
    public static function candidates(): array
    {
        $candidates = [self::resolve(), self::smsAppSecret()];

        $configured = ModuleConfig::get('messaging.zoom.webhook_secrets', []);

        foreach (is_array($configured) ? $configured : [$configured] as $secret) {
            $candidates[] = $secret;
        }

        $out = [];

        foreach ($candidates as $secret) {
            if (is_string($secret) && $secret !== '' && ! in_array($secret, $out, true)) {
                $out[] = $secret;
            }
        }

        return $out;
    }

    /**
     * The user-level SMS app's secret token, read defensively: the integration
     * is declared by the host application, not by this package, and an
     * application that declares it without a `webhook_secret` field (as every
     * one did before 4.19.0) simply has none.
     */
    private static function smsAppSecret(): ?string
    {
        try {
            $registry = app(IntegrationRegistry::class);

            if (! $registry->exists('zoom_sms')) {
                return null;
            }

            $fields = (array) ($registry->definition('zoom_sms')['fields'] ?? []);

            if (! array_key_exists('webhook_secret', $fields)) {
                return null;
            }

            $secret = $registry->credential('zoom_sms', 'webhook_secret');

            return is_string($secret) ? $secret : null;
        } catch (\Throwable $e) {
            // A secret we cannot read is a secret we do not accept with - never
            // a reason to reject a delivery signed with one we can.
            return null;
        }
    }
}
