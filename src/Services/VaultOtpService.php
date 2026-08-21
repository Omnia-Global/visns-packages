<?php

namespace Visnsstudio\VisnsPackages\Services;

use InvalidArgumentException;
use PragmaRX\Google2FA\Google2FA;
use Visnsstudio\VisnsPackages\Models\VaultEntry;

/**
 * Turns whatever a provider hands a user into a seed this module can generate
 * codes from, and then generates them.
 *
 * Two things about the underlying library are worth knowing, because both are
 * worked around here:
 *
 *  - Google2FA enforces "Google Authenticator compatibility" by default, which
 *    means it rejects any base32 secret whose length is not a power of two. Real
 *    providers issue 20- and 26-character secrets constantly, and refusing them
 *    would make the vault unable to hold half the credentials it exists for. The
 *    check is turned off; the genuine constraints (valid base32 alphabet, at
 *    least 128 bits) are still enforced, here and by the library.
 *
 *  - `Google2FA::getCurrentOtp()` reads `microtime()` directly, so it ignores a
 *    frozen clock entirely. Every code in this class is generated from an
 *    explicit counter derived from `now()`, which means Carbon's test clock
 *    works, and which is the only reason the expiry arithmetic is testable at
 *    all.
 */
class VaultOtpService
{
    /** Base32 with no padding, per RFC 4648's alphabet. */
    private const BASE32 = '/^[A-Z2-7]+$/';

    /** @var array<int, string> */
    private const ALGORITHMS = ['sha1', 'sha256', 'sha512'];

    /** @var array<int, int> */
    private const DIGITS = [6, 8];

    private const MIN_PERIOD = 15;
    private const MAX_PERIOD = 120;

    /**
     * Below 128 bits the library refuses the secret, and it is right to: 16
     * base32 characters is the floor.
     */
    private const MIN_SECRET_LENGTH = 16;

    /**
     * Accept either a bare base32 secret or a full `otpauth://totp/...` URI and
     * return the four things an entry stores.
     *
     * The URI form is what a user actually has - it is what sits behind the QR
     * code every provider shows - and it is the only form that carries the
     * digits, period and algorithm. Pasting it is therefore the supported path;
     * a bare secret is accepted too and takes the TOTP defaults.
     *
     * @return array{secret: string, digits: int, period: int, algorithm: string}
     *
     * @throws InvalidArgumentException
     */
    public function normaliseSecret(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            throw new InvalidArgumentException('The authenticator secret is empty.');
        }

        return str_starts_with(strtolower($input), 'otpauth://')
            ? $this->fromUri($input)
            : $this->fromBareSecret($input);
    }

    /**
     * The code for this entry right now, and how long it is still good for.
     *
     * `expires_in` counts down to the period boundary, not to a fixed lifetime:
     * a code fetched one second before the boundary is genuinely about to stop
     * working, and a UI that showed it a full period of runway would have people
     * typing dead codes.
     *
     * @return array{code: string, expires_in: int, period: int}
     *
     * @throws InvalidArgumentException
     */
    public function currentCode(VaultEntry $entry): array
    {
        $secret = trim((string) ($entry->totp_secret ?? ''));

        if ($secret === '') {
            throw new InvalidArgumentException('This entry has no authenticator secret.');
        }

        $normalised = [
            'secret' => $secret,
            'digits' => (int) ($entry->totp_digits ?: 6),
            'period' => (int) ($entry->totp_period ?: 30),
            'algorithm' => strtolower((string) ($entry->totp_algorithm ?: 'sha1')),
        ];

        $period = $normalised['period'];
        $timestamp = now()->getTimestamp();

        return [
            'code' => $this->codeAt($normalised, $timestamp),
            'expires_in' => $period - ($timestamp % $period),
            'period' => $period,
        ];
    }

    /**
     * Prove a normalised secret can actually produce a code, by producing one.
     *
     * Called before anything is stored. The alternative - trusting the shape of
     * the string - buys an entry that looks fine in the list and fails the first
     * time somebody needs to log in with it, which is the worst possible moment
     * to find out.
     *
     * @param array{secret: string, digits: int, period: int, algorithm: string} $normalised
     *
     * @throws InvalidArgumentException
     */
    public function validateSecret(array $normalised): void
    {
        try {
            $code = $this->codeAt($normalised, now()->getTimestamp());
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                'That authenticator secret could not be used to generate a code.',
                0,
                $e
            );
        }

        if (! is_string($code) || strlen($code) !== $normalised['digits']) {
            throw new InvalidArgumentException(
                'That authenticator secret could not be used to generate a code.'
            );
        }
    }

    /**
     * @param array{secret: string, digits: int, period: int, algorithm: string} $normalised
     */
    private function codeAt(array $normalised, int $timestamp): string
    {
        $google2fa = new Google2FA();

        // See the class docblock: real secrets are routinely not a power of two
        // characters long.
        $google2fa->setEnforceGoogleAuthenticatorCompatibility(false);
        $google2fa->setOneTimePasswordLength($normalised['digits']);
        $google2fa->setAlgorithm($normalised['algorithm']);
        $google2fa->setKeyRegeneration($normalised['period']);

        // An explicit counter, not getCurrentOtp(): the library's own clock
        // reads microtime() and cannot be frozen.
        return $google2fa->oathTotp(
            $normalised['secret'],
            intdiv($timestamp, $normalised['period'])
        );
    }

    /**
     * @return array{secret: string, digits: int, period: int, algorithm: string}
     */
    private function fromBareSecret(string $input): array
    {
        return [
            'secret' => $this->cleanSecret($input),
            'digits' => 6,
            'period' => 30,
            'algorithm' => 'sha1',
        ];
    }

    /**
     * @return array{secret: string, digits: int, period: int, algorithm: string}
     */
    private function fromUri(string $input): array
    {
        $parts = parse_url($input);

        if ($parts === false || strtolower((string) ($parts['host'] ?? '')) !== 'totp') {
            // HOTP is counter-based: it cannot be displayed as a rotating code
            // and storing one here would be a promise the module cannot keep.
            throw new InvalidArgumentException(
                'Only otpauth://totp/ URIs are supported.'
            );
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        $secret = $this->cleanSecret((string) ($query['secret'] ?? ''));

        $digits = array_key_exists('digits', $query) && trim((string) $query['digits']) !== ''
            ? (int) $query['digits']
            : 6;

        $period = array_key_exists('period', $query) && trim((string) $query['period']) !== ''
            ? (int) $query['period']
            : 30;

        $algorithm = array_key_exists('algorithm', $query) && trim((string) $query['algorithm']) !== ''
            ? strtolower(trim((string) $query['algorithm']))
            : 'sha1';

        if (! in_array($digits, self::DIGITS, true)) {
            throw new InvalidArgumentException(
                'An authenticator code must be 6 or 8 digits.'
            );
        }

        if ($period < self::MIN_PERIOD || $period > self::MAX_PERIOD) {
            throw new InvalidArgumentException(
                'An authenticator period must be between ' . self::MIN_PERIOD
                . ' and ' . self::MAX_PERIOD . ' seconds.'
            );
        }

        if (! in_array($algorithm, self::ALGORITHMS, true)) {
            throw new InvalidArgumentException(
                'Unsupported authenticator algorithm: ' . $algorithm . '.'
            );
        }

        return compact('secret', 'digits', 'period', 'algorithm');
    }

    /**
     * Strip the formatting authenticator apps add for readability - spaces and
     * hyphens between groups, lower case, trailing `=` padding - and then insist
     * on what is left being base32.
     */
    private function cleanSecret(string $secret): string
    {
        $secret = strtoupper(
            str_replace([' ', '-', "\t", "\n", "\r"], '', trim($secret))
        );

        $secret = rtrim($secret, '=');

        if ($secret === '' || ! preg_match(self::BASE32, $secret)) {
            throw new InvalidArgumentException(
                'That is not a valid base32 authenticator secret.'
            );
        }

        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new InvalidArgumentException(
                'An authenticator secret must be at least '
                . self::MIN_SECRET_LENGTH . ' characters.'
            );
        }

        return $secret;
    }
}
