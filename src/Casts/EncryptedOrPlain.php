<?php

namespace Visnsstudio\VisnsPackages\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypt on write; tolerate plaintext on read.
 *
 * Laravel's own `encrypted` cast throws a DecryptException the moment it meets
 * a value that was written before the cast existed. That is fine in an app you
 * control and wrong in a shared package: adding encryption to
 * `oauth_connections` would break every consuming project that already has
 * tokens in that table, and it would break them at the point of READING a
 * token — i.e. mid-sync, not at deploy.
 *
 * So: anything that decrypts is returned decrypted, anything that does not is
 * assumed to be a legacy plaintext value and returned as-is. Every write is
 * encrypted, so a row heals itself the first time it is saved.
 */
class EncryptedOrPlain implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            // Written before this cast was added, or under a different
            // APP_KEY. Either way the caller gets what is actually stored
            // rather than an exception it cannot do anything about.
            return $value;
        }
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return [$key => $value];
        }

        return [$key => Crypt::encryptString((string) $value)];
    }
}
