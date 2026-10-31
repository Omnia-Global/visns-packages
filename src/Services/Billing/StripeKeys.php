<?php

namespace Visnsstudio\VisnsPackages\Services\Billing;

/**
 * The one reading of what a pasted Stripe credential is.
 *
 * Billing runs on a RESTRICTED key (rk_live_ / rk_test_) with only the
 * permissions it needs. A secret key (sk_) can do anything to the account,
 * payouts included, so it is refused with a sentence, at save time and again
 * before any client is built. The mode is read from the key's prefix, so the
 * mode and the key cannot disagree.
 */
final class StripeKeys
{
    public const KEY_FIELD = 'restricted_key';

    public const SECRET_FIELD = 'webhook_secret';

    public const MODE_FIELD = 'livemode';

    /** What is wrong with a key, or null when it is a restricted key. */
    public static function keyProblem(?string $key): ?string
    {
        $key = trim((string) $key);

        if ($key === '') {
            return 'A Stripe restricted key (rk_live_ or rk_test_) is required.';
        }

        if (str_starts_with($key, 'sk_')) {
            return 'That is a secret key (sk_). Create a restricted key (rk_) in the Stripe Dashboard with only the permissions billing needs, and paste that instead.';
        }

        if (str_starts_with($key, 'pk_')) {
            return 'That is a publishable key (pk_). Billing needs a restricted key (rk_live_ or rk_test_).';
        }

        if (! preg_match('/^rk_(live|test)_[A-Za-z0-9]+$/', $key)) {
            return 'A Stripe restricted key starts rk_live_ or rk_test_ and has no spaces.';
        }

        return null;
    }

    /** true for a live key, false for a test or sandbox key, null when unreadable. */
    public static function livemode(?string $key): ?bool
    {
        $key = trim((string) $key);

        if (str_starts_with($key, 'rk_live_')) {
            return true;
        }

        if (str_starts_with($key, 'rk_test_')) {
            return false;
        }

        return null;
    }

    /**
     * Everything wrong with a set of credentials, or null.
     *
     * `$requireKey` is false at save time: a form that corrects the webhook
     * secret alone must not be refused for a key it did not touch.
     */
    public static function problem(array $credentials, bool $requireKey = true): ?string
    {
        $key = trim((string) ($credentials[self::KEY_FIELD] ?? ''));

        if ($key !== '' || $requireKey) {
            if ($problem = self::keyProblem($key)) {
                return $problem;
            }
        }

        $secret = trim((string) ($credentials[self::SECRET_FIELD] ?? ''));

        if ($secret !== '' && ! str_starts_with($secret, 'whsec_')) {
            return 'The webhook signing secret starts whsec_. Copy it from the endpoint in Stripe Workbench.';
        }

        $mode = strtolower(trim((string) ($credentials[self::MODE_FIELD] ?? '')));
        $keyMode = self::livemode($key);

        if ($keyMode !== null && in_array($mode, ['live', 'test'], true) && ($mode === 'live') !== $keyMode) {
            return $keyMode
                ? 'The key is a live key but the mode says test. Choose live, or paste the sandbox key.'
                : 'The key is a test key but the mode says live. Choose test, or paste the live key.';
        }

        return null;
    }

    /**
     * The integration's save-time validator (`validate` in its definition).
     *
     * @return string|null the refusal sentence
     */
    public static function validate(array $credentials): ?string
    {
        return self::problem($credentials, false);
    }
}
