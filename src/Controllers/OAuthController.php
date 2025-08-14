<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Visnsstudio\VisnsPackages\Services\OAuthManager;
use Illuminate\Support\Facades\Log;

class OAuthController extends \App\Http\Controllers\Controller
{
    protected OAuthManager $oauthManager;

    public function __construct(OAuthManager $oauthManager)
    {
        $this->oauthManager = $oauthManager;
    }

    /**
     * Redirect to provider for authorization
     */
    public function redirectToProvider(string $provider): RedirectResponse
    {
        try {
            $authUrl = $this->oauthManager->getAuthorizationUrl($provider);
            
            if (!$authUrl) {
                return redirect()
                    ->route('settings.integrations')
                    ->with('error', "Provider '{$provider}' not found or not configured");
            }

            return redirect()->away($authUrl);

        } catch (\Exception $e) {
            Log::error("OAuth authorization failed for {$provider}", [
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('settings.integrations')
                ->with('error', "Authorization failed: {$e->getMessage()}");
        }
    }

    /**
     * Handle OAuth callback
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $code = $request->get('code');
        $state = $request->get('state');
        $error = $request->get('error');

        // Handle OAuth errors
        if ($error) {
            return redirect()
                ->to('/admin/integrations')
                ->with('error', "OAuth authorization failed: {$error}");
        }

        if (!$code) {
            return redirect()
                ->to('/admin/integrations')
                ->with('error', 'OAuth authorization failed: No authorization code received');
        }

        if (!$state) {
            return redirect()
                ->to('/admin/integrations')
                ->with('error', 'OAuth authorization failed: No state parameter received');
        }

        // Handle the callback
        $result = $this->oauthManager->handleCallback($provider, $code, $state);

        return redirect()
            ->to('/admin/integrations')
            ->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Get provider status
     */
    public function status(string $provider): JsonResponse
    {
        try {
            $status = $this->oauthManager->getConnectionStatus($provider);
            return response()->json([
                'success' => true,
                'provider' => $provider,
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Test provider connection
     */
    public function test(string $provider): JsonResponse
    {
        try {
            $result = $this->oauthManager->testConnection($provider);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Preview data before sync
     */
    public function preview(Request $request, string $provider): JsonResponse
    {
        $request->validate([
            'data_type' => 'required|string',
            'options' => 'array',
        ]);

        try {
            $dataType = $request->input('data_type');
            $options = $request->input('options', []);
            
            $result = $this->oauthManager->previewData($provider, $dataType, $options);
            
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data_type' => $request->input('data_type'),
            ], 400);
        }
    }

    /**
     * Disconnect provider
     */
    public function disconnect(string $provider): JsonResponse
    {
        try {
            $result = $this->oauthManager->disconnect($provider);
            
            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'Successfully disconnected',
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No active connection found',
                ], 404);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Sync data for provider
     */
    public function sync(Request $request, string $provider): JsonResponse
    {
        $request->validate([
            'type' => 'required|string',
            'confirmed' => 'required|boolean',
            'records' => 'array',
            'skipped_records' => 'array',
            'settings' => 'array',
            'manual_matches' => 'array',
        ]);

        $dataType = $request->get('type');
        $options = array_merge($request->get('options', []), [
            'confirmed' => $request->get('confirmed'),
            'records' => $request->get('records', []),
            'skipped_records' => $request->get('skipped_records', []),
            'settings' => $request->get('settings', []),
            'manual_matches' => $request->get('manual_matches', []),
            'preview_id' => $request->get('preview_id'),
        ]);

        try {
            $result = $this->oauthManager->syncData($provider, $dataType, $options);
            return response()->json($result);
        } catch (\Exception $e) {
            Log::error("OAuth sync failed", [
                'provider' => $provider,
                'data_type' => $dataType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get all providers
     */
    public function providers(): JsonResponse
    {
        try {
            $providers = $this->oauthManager->getAvailableProviders();
            return response()->json([
                'success' => true,
                'providers' => $providers,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}