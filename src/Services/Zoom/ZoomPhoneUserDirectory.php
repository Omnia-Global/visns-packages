<?php

namespace Visnsstudio\VisnsPackages\Services\Zoom;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Visnsstudio\VisnsPackages\Services\IntegrationRegistry;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The Zoom Phone roster: who has a handset, and what extension it is.
 *
 * `GET /phone/users`, cached. This is the half of the presence feature that has
 * to come from the REST API — the webhook stream can only ever tell you about
 * extensions that have had a call, so a directory built from webhooks alone
 * would start empty and never list the people who have not rung anyone today.
 *
 * It is also the half that barely changes: staff join a few times a year. Ten
 * minutes of cache turns "every header popup opening" into roughly one API call
 * per deployment per ten minutes.
 *
 * Failure is reported, never thrown. The popup has to keep working — and has to
 * SAY that it is degraded — when Zoom is down or the credentials are missing.
 */
class ZoomPhoneUserDirectory extends ZoomApiClient
{
    /** Zoom's maximum for this endpoint. */
    private const PAGE_SIZE = 100;

    /** A hard stop, so a paging bug cannot walk forever on a webhook thread. */
    private const MAX_PAGES = 20;

    private const CACHE_KEY = 'visns_zoom_phone_users';

    /**
     * Are there credentials to talk to Zoom with at all?
     *
     * Checked before the roster is fetched so an unconfigured deployment gets
     * "Zoom Phone is not connected" rather than a failed request and an empty
     * list, which look the same from the browser and mean very different things.
     */
    public function configured(): bool
    {
        try {
            if (app(IntegrationRegistry::class)->isConfigured('zoom')) {
                return true;
            }
        } catch (\Throwable $e) {
            // Fall through to the config credentials.
        }

        return trim((string) ModuleConfig::get('call_queue.api.account_id')) !== ''
            && trim((string) ModuleConfig::get('call_queue.api.client_id')) !== ''
            && trim((string) ModuleConfig::get('call_queue.api.client_secret')) !== '';
    }

    /**
     * Every Zoom Phone user, sorted by extension.
     *
     * @param  bool  $fresh  Skip the cache (the settings screen's "refresh").
     * @return array{success: bool, users: array<int, array<string, mixed>>, error?: string}
     */
    public function users(bool $fresh = false): array
    {
        if (! $this->configured()) {
            return [
                'success' => false,
                'users' => [],
                'error' => 'Zoom is not connected.',
            ];
        }

        $ttl = (int) ModuleConfig::get('call_queue.presence.roster_cache_ttl', 600);

        if ($fresh || $ttl <= 0) {
            $result = $this->fetch();

            // Only a good answer is worth caching: caching the failure would
            // hold the popup in an error state for ten minutes after Zoom came
            // back.
            if ($result['success'] && $ttl > 0) {
                Cache::put(self::CACHE_KEY, $result, $ttl);
            }

            return $result;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && ($cached['success'] ?? false)) {
            return $cached;
        }

        $result = $this->fetch();

        if ($result['success']) {
            Cache::put(self::CACHE_KEY, $result, $ttl);
        }

        return $result;
    }

    /** Drop the cached roster — for a settings save that changed credentials. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{success: bool, users: array<int, array<string, mixed>>, error?: string}
     */
    private function fetch(): array
    {
        $users = [];
        $token = '';
        $pages = 0;

        do {
            $result = $this->request(
                'GET',
                '/phone/users?page_size=' . self::PAGE_SIZE .
                    ($token !== '' ? '&next_page_token=' . urlencode($token) : '')
            );

            if (! $result['success']) {
                return [
                    'success' => false,
                    'users' => [],
                    'error' => $this->errorMessage($result),
                ];
            }

            foreach ((array) Arr::get($result, 'data.users', []) as $user) {
                $row = $this->normalise($user);

                if ($this->excluded($row)) {
                    continue;
                }

                $users[] = $row;
            }

            $token = (string) Arr::get($result, 'data.next_page_token', '');
            $pages++;
        } while ($token !== '' && $pages < self::MAX_PAGES);

        usort($users, static function (array $a, array $b) {
            $left = $a['extension_number'] ?? '';
            $right = $b['extension_number'] ?? '';

            // Extensions are numeric strings of the same length in practice, but
            // a site mixing 3- and 4-digit extensions would sort "1000" before
            // "208" on a plain string compare.
            if ($left !== '' && $right !== '' && ctype_digit($left) && ctype_digit($right)) {
                return (int) $left <=> (int) $right;
            }

            if ($left === '' xor $right === '') {
                return $left === '' ? 1 : -1;
            }

            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return ['success' => true, 'users' => $users];
    }

    /**
     * Accounts the roster should not show, by email.
     *
     * A Zoom tenant usually carries at least one licence that is not a person —
     * a shared admin login, a service account — and Zoom happily reports it
     * with whatever display name it was created under, which reads in the
     * popup as a staff member who is never at their desk. The application
     * names them in `call_queue.presence.exclude_emails`.
     */
    private function excluded(array $row): bool
    {
        $excluded = (array) ModuleConfig::get('call_queue.presence.exclude_emails', []);

        if ($excluded === [] || ($row['email'] ?? null) === null) {
            return false;
        }

        foreach ($excluded as $email) {
            if (is_string($email) && strcasecmp(trim($email), $row['email']) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * One roster row, in the shape the popup reads.
     *
     * Zoom's `status` here is the ACCOUNT's state ("activate"/"deactivate"), not
     * anything to do with calls — a deactivated extension is one that cannot be
     * rung at all, which is worth knowing and is not the same as "free".
     */
    private function normalise(array $user): array
    {
        $name = trim((string) (Arr::get($user, 'name') ?? ''));

        if ($name === '') {
            $name = trim(
                (string) Arr::get($user, 'first_name', '') . ' '
                . (string) Arr::get($user, 'last_name', '')
            );
        }

        $email = trim((string) Arr::get($user, 'email', ''));

        return [
            'id' => (string) Arr::get($user, 'id', ''),
            'name' => $name !== '' ? $name : ($email !== '' ? $email : 'Unnamed extension'),
            'email' => $email === '' ? null : $email,
            'extension_number' => trim((string) Arr::get($user, 'extension_number', '')),
            'active' => strtolower(trim((string) Arr::get($user, 'status', 'activate'))) !== 'deactivate',
            'phone_numbers' => array_values(array_filter(array_map(
                static fn ($number) => trim((string) Arr::get($number, 'number', '')),
                (array) Arr::get($user, 'phone_numbers', [])
            ))),
        ];
    }

    /** Zoom's own error text, when it gave one. */
    public function errorMessage(array $result): string
    {
        $message = Arr::get($result, 'data.message');

        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        $code = (int) Arr::get($result, 'http_code', 0);

        return $code > 0
            ? 'Zoom returned HTTP ' . $code . '.'
            : 'Could not reach Zoom.';
    }
}
