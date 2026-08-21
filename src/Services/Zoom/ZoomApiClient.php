<?php

namespace Visnsstudio\VisnsPackages\Services\Zoom;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Request/token plumbing for Zoom's Server-to-Server OAuth REST API.
 *
 * Credentials and URLs come from `visns-packages.call_queue.api.*`; nothing here
 * reads the environment directly, because the environment reader returns null
 * once an application has run `config:cache` — which is exactly the state
 * production runs in.
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
        $this->accountId = (string) ModuleConfig::get('call_queue.api.account_id');
        $this->clientId = (string) ModuleConfig::get('call_queue.api.client_id');
        $this->clientSecret = (string) ModuleConfig::get('call_queue.api.client_secret');
        $this->baseUrl = (string) ModuleConfig::get('call_queue.api.base_url', 'https://api.zoom.us/v2');
        $this->tokenUrl = (string) ModuleConfig::get('call_queue.api.token_url', 'https://zoom.us/oauth/token');
    }

    /**
     * Get OAuth access token using Server-to-Server credentials.
     * Cached for 55 minutes (tokens last 60 minutes).
     */
    protected function getAccessToken(): string
    {
        return Cache::remember(self::CACHE_KEY_TOKEN, self::TOKEN_TTL_SECONDS, function () {
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

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
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
            ];
        }

        // Token expired - clear cache and retry once
        if ($httpCode === 401) {
            Cache::forget(self::CACHE_KEY_TOKEN);
            $token = $this->getAccessToken();

            $headers[0] = 'Authorization: Bearer ' . $token;
            $ch = curl_init($url);
            $opts[CURLOPT_HTTPHEADER] = $headers;
            curl_setopt_array($ch, $opts);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                return [
                    'success' => true,
                    'http_code' => $httpCode,
                    'data' => $response ? json_decode($response, true) : null,
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
        ];
    }
}
