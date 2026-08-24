<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\IntegrationSetting;
use Visnsstudio\VisnsPackages\Services\IntegrationRegistry;
use Visnsstudio\VisnsPackages\Services\OAuthManager;

/**
 * The settings screen behind Settings → Integrations.
 *
 * Credentials go IN through here and never come back out: every response
 * reports which fields are set, not what they hold. The OAuth redirect and
 * callback are not here — OAuthController already owns those, and this
 * controller hands the UI the URL to send the browser to.
 */
class IntegrationsController extends \App\Http\Controllers\Controller
{
    public function __construct(
        private IntegrationRegistry $registry,
        private OAuthManager $oauth,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorizeIntegrations();

        return response()->json([
            'data' => $this->registry->summary(),
        ]);
    }

    public function show(string $provider): JsonResponse
    {
        $this->authorizeIntegrations();
        $this->assertExists($provider);

        return response()->json([
            'data' => collect($this->registry->summary())
                ->firstWhere('provider', $provider),
        ]);
    }

    /**
     * Save the credentials somebody typed in.
     *
     * A field the form did not send, or sent as null, is left alone — the form
     * was never given the current secrets to send back, so "absent" cannot
     * mean "clear this". An explicit empty string is the clear.
     */
    public function update(Request $request, string $provider): JsonResponse
    {
        $this->authorizeIntegrations();
        $this->assertExists($provider);

        $definition = $this->registry->definition($provider);
        $allowed = array_keys($definition['fields'] ?? []);

        $validated = $request->validate([
            'credentials' => 'sometimes|array',
            'options' => 'sometimes|array',
            'is_enabled' => 'sometimes|boolean',
        ]);

        // Only keys this integration actually declares. Without this, the
        // endpoint stores whatever it is posted.
        $incoming = array_intersect_key(
            $validated['credentials'] ?? [],
            array_flip($allowed)
        );

        $setting = IntegrationSetting::firstOrNew(['provider' => $provider]);

        $setting->credentials = $setting->mergeCredentials($incoming);

        if (array_key_exists('options', $validated)) {
            $setting->options = array_merge($setting->options ?? [], $validated['options']);
        }

        if (array_key_exists('is_enabled', $validated)) {
            $setting->is_enabled = $validated['is_enabled'];
        }

        $setting->updated_by_user_id = $request->user()?->id;
        $setting->save();

        $this->registry->forget($provider);

        Log::info('Integration credentials updated', [
            'provider' => $provider,
            'user_id' => $request->user()?->id,
            // The keys, never the values.
            'fields' => array_keys($incoming),
        ]);

        return response()->json([
            'message' => 'Saved',
            'data' => collect($this->registry->summary())->firstWhere('provider', $provider),
        ]);
    }

    /**
     * Forget every stored credential for an integration.
     *
     * This does not touch .env — a value that came from there will still
     * resolve afterwards, and the response says so rather than claiming the
     * integration is now blank.
     */
    public function destroy(Request $request, string $provider): JsonResponse
    {
        $this->authorizeIntegrations();
        $this->assertExists($provider);

        $setting = IntegrationSetting::forProvider($provider);

        if ($setting) {
            $setting->credentials = [];
            $setting->last_tested_at = null;
            $setting->last_test_status = null;
            $setting->last_test_message = null;
            $setting->updated_by_user_id = $request->user()?->id;
            $setting->save();
        }

        // An OAuth integration also has a token to drop, or "disconnected"
        // would leave it still able to call the API.
        if ($this->registry->driver($provider) === 'oauth2') {
            $this->oauth->disconnect($provider);
        }

        $this->registry->forget($provider);

        Log::warning('Integration credentials cleared', [
            'provider' => $provider,
            'user_id' => $request->user()?->id,
        ]);

        $summary = collect($this->registry->summary())->firstWhere('provider', $provider);

        return response()->json([
            'message' => 'Cleared',
            // True when .env still supplies the required fields, so the UI can
            // say "still configured from the server environment" instead of
            // showing a blank card the user cannot explain.
            'still_configured_from_env' => $this->registry->isConfigured($provider),
            'data' => $summary,
        ]);
    }

    /**
     * The URL to send the browser to for consent.
     *
     * Returned as JSON rather than redirected, because the caller is a fetch
     * from a React page: a 302 to a third-party host fails CORS, where a URL
     * the page can assign to `window.location` does not.
     */
    public function authorizeUrl(string $provider): JsonResponse
    {
        $this->authorizeIntegrations();
        $this->assertExists($provider);

        if ($this->registry->driver($provider) !== 'oauth2') {
            return response()->json([
                'message' => 'This integration uses an API key, not OAuth.',
            ], 422);
        }

        if (!$this->registry->isConfigured($provider)) {
            return response()->json([
                'message' => 'Fill in the client ID and secret before connecting.',
            ], 422);
        }

        $url = $this->oauth->getAuthorizationUrl($provider);

        if (!$url) {
            return response()->json([
                'message' => 'Could not build the consent URL for this provider.',
            ], 422);
        }

        return response()->json(['data' => ['url' => $url]]);
    }

    /**
     * Prove the credentials work, and remember the answer.
     *
     * The result is stored on the row so the card can show it after a reload —
     * otherwise "it worked when I set it up" is unverifiable.
     */
    public function test(Request $request, string $provider): JsonResponse
    {
        $this->authorizeIntegrations();
        $this->assertExists($provider);

        $definition = $this->registry->definition($provider);
        $ok = false;
        $message = 'No test is defined for this integration.';

        try {
            if ($this->registry->driver($provider) === 'oauth2') {
                $result = $this->oauth->testConnection($provider);
                $ok = (bool) ($result['success'] ?? false);
                $message = $result['message'] ?? ($ok ? 'Connected' : 'The provider refused the request.');
            } elseif (is_callable($definition['test'] ?? null)) {
                // An api_key integration supplies its own probe, because only
                // it knows what a cheap authenticated call looks like.
                $result = ($definition['test'])($this->registry->credentials($provider));

                if (is_array($result)) {
                    $ok = (bool) ($result['success'] ?? false);
                    $message = $result['message'] ?? ($ok ? 'Connected' : 'Failed');
                } else {
                    $ok = (bool) $result;
                    $message = $ok ? 'Connected' : 'The service refused the credentials.';
                }
            }
        } catch (\Throwable $e) {
            // A thrown probe is a failed test, not a 500 — the user is on a
            // settings page trying to find out what is wrong.
            $ok = false;
            $message = $e->getMessage();

            Log::warning('Integration test threw', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
        }

        $setting = IntegrationSetting::firstOrNew(['provider' => $provider]);
        $setting->last_tested_at = now();
        $setting->last_test_status = $ok ? 'ok' : 'failed';
        $setting->last_test_message = mb_substr((string) $message, 0, 1000);
        $setting->updated_by_user_id = $request->user()?->id;
        $setting->save();

        $this->registry->forget($provider);

        return response()->json([
            'data' => [
                'success' => $ok,
                'message' => $message,
                'tested_at' => $setting->last_tested_at->toIso8601String(),
            ],
        ], $ok ? 200 : 422);
    }

    private function assertExists(string $provider): void
    {
        if (!$this->registry->exists($provider)) {
            abort(404, "Unknown integration [{$provider}].");
        }
    }

    /**
     * Integrations hold the keys to every connected system, so the gate is
     * separate from ordinary settings and defaults to closed when the host app
     * has not granted it to anyone.
     */
    private function authorizeIntegrations(): void
    {
        $permission = config('visns-packages.integrations_permission', 'manage integrations');

        if (!$permission) {
            return;
        }

        $user = request()->user();

        if (!$user) {
            abort(403);
        }

        // Spatie's `can` when the app uses it; a plain gate otherwise.
        if (method_exists($user, 'can') && $user->can($permission)) {
            return;
        }

        abort(403, 'You do not have permission to manage integrations.');
    }
}
