<?php

namespace Visnsstudio\VisnsPackages\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A provider told us a user removed the app (Zoom's `app_deauthorized`), and
 * the package has deactivated this connection, emptied its tokens and removed
 * the user's profile from its metadata.
 *
 * Dispatched once per connection, AFTER the row is saved, so a host
 * application can remove what it holds about the connection - the client's
 * SMS lines that point at it, say. Carries ids only: never a token, never the
 * user's name or email.
 */
class OAuthConnectionDeauthorized
{
    use Dispatchable;

    public function __construct(
        public int $connectionId,
        public string $provider,
        public ?int $clientId,
        public string $providerUserId,
    ) {
    }
}
