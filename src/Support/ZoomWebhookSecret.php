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
}
