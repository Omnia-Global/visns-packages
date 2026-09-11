<?php

namespace Visnsstudio\VisnsPackages\Services\Zoom;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Services\IntegrationRegistry;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Request/token plumbing for Zoom's Server-to-Server OAuth REST API.
 *
 * Credentials resolve through the `zoom` integration setting (the record the
 * settings UI writes, then its declared env vars) and fall back to
 * `visns-packages.call_queue.api.*` for deployments configured before the
 * integrations screen existed. Nothing here reads the environment directly,
 * because the environment reader returns null once an application has run
 * `config:cache` — which is exactly the state production runs in.
 */
class ZoomApiClient
{
    protected string $accountId;
    protected string $clientId;
    protected string $clientSecret;
    protected string $baseUrl;
    protected string $tokenUrl;

    /** Package-prefixed so it cannot collide with an application's own keys. */
    private const CACHE_KEY_TOKEN = 'visns_zoom_oauth_token';

    /** Tokens last 60 minutes; cached for 55 so one never expires mid-flight. */
    private const TOKEN_TTL_SECONDS = 3300;

    public function __construct()
    {
        $registry = app(IntegrationRegistry::class);
        $fromIntegration = $registry->exists('zoom');

        $this->accountId = (string) ($fromIntegration
            ? $registry->credential('zoom', 'account_id', ModuleConfig::get('call_queue.api.account_id'))
            : ModuleConfig::get('call_queue.api.account_id'));
        $this->clientId = (string) ($fromIntegration
            ? $registry->credential('zoom', 'client_id', ModuleConfig::get('call_queue.api.client_id'))
            : ModuleConfig::get('call_queue.api.client_id'));
        $this->clientSecret = (string) ($fromIntegration
            ? $registry->credential('zoom', 'client_secret', ModuleConfig::get('call_queue.api.client_secret'))
            : ModuleConfig::get('call_queue.api.client_secret'));
        $this->baseUrl = (string) ModuleConfig::get('call_queue.api.base_url', 'https://api.zoom.us/v2');
        $this->tokenUrl = (string) ModuleConfig::get('call_queue.api.token_url', 'https://zoom.us/oauth/token');
    }

    /**
     * Get OAuth access token using Server-to-Server credentials.
     * Cached for 55 minutes (tokens last 60 minutes).
     */
    protected function getAccessToken(): string
    {
        // Keyed by credential fingerprint so saving new credentials in the
        // settings UI takes effect immediately instead of after the old
        // token's TTL.
        $cacheKey = self::CACHE_KEY_TOKEN . ':' . substr(
            sha1($this->accountId . '|' . $this->clientId . '|' . $this->clientSecret),
            0,
            12
        );

        return Cache::remember($cacheKey, self::TOKEN_TTL_SECONDS, function () {
            $ch = curl_init($this->tokenUrl);

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'account_credentials',
                    'account_id' => $this->accountId,
                ]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                Log::error('Zoom OAuth token request failed', [
                    'http_code' => $httpCode,
                    'response' => $response,
                ]);
                throw new \Exception('Failed to obtain Zoom access token');
            }

            $data = json_decode($response, true);

            return $data['access_token'];
        });
    }

    /**
     * Make an authenticated request to the Zoom API.
     *
     * The result carries `headers` alongside `success`, `http_code` and `data`.
     * Nothing that reads a Zoom result is obliged to look at them - they are
     * there for the one caller that must: Services\Sms\ZoomSmsTransport reads
     * `Retry-After` off a 429 so a rate-limited line waits for as long as Zoom
     * asked rather than for a number we invented.
     */
    protected function request(string $method, string $endpoint, ?array $body = null): array
    {
        $token = $this->getAccessToken();
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        $received = [];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HEADERFUNCTION => $this->headerCollector($received),
        ];

        if ($body !== null && in_array(strtoupper($method), ['POST', 'PATCH', 'PUT'])) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 204 No Content is a success for PATCH
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'http_code' => $httpCode,
                'data' => $response ? json_decode($response, true) : null,
                'headers' => $received,
            ];
        }

        // Token expired - clear cache and retry once
        if ($httpCode === 401) {
            Cache::forget(self::CACHE_KEY_TOKEN);
            $token = $this->getAccessToken();

            $headers[0] = 'Authorization: Bearer ' . $token;
            $ch = curl_init($url);
            // The retry's own headers, not the dead token's - a bag left
            // holding both would report whichever response spoke last per name.
            $received = [];
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_HEADERFUNCTION] = $this->headerCollector($received);
            curl_setopt_array($ch, $opts);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                return [
                    'success' => true,
                    'http_code' => $httpCode,
                    'data' => $response ? json_decode($response, true) : null,
                    'headers' => $received,
                ];
            }
        }

        Log::error('Zoom API request failed', [
            'method' => $method,
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'response' => $response,
        ]);

        return [
            'success' => false,
            'http_code' => $httpCode,
            'data' => $response ? json_decode($response, true) : null,
            'headers' => $received,
        ];
    }

    /**
     * A `CURLOPT_HEADERFUNCTION` that files each response header into `$bag`,
     * in the shape Laravel's HTTP client uses: `['Name' => ['value', …]]`.
     *
     * curl hands the callback one line at a time, status line included, and
     * **its return value must be the number of bytes it was given** - anything
     * else aborts the transfer, which is the one way a header collector can
     * break a request that was otherwise fine.
     *
     * A header sent twice (Set-Cookie, and several of Zoom's rate-limit
     * headers) keeps both values rather than the last, which is why every
     * entry is a list.
     *
     * @param  array<string, array<int, string>>  $bag
     */
    private function headerCollector(array &$bag): callable
    {
        return function ($ch, $line) use (&$bag) {
            $length = strlen($line);

            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $name = trim($parts[0]);

                if ($name !== '') {
                    $bag[$name][] = trim($parts[1]);
                }
            }

            return $length;
        };
    }
}
