<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use Visnsstudio\VisnsPackages\Support\MinimalUserPayload;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\Relations;

use Carbon\Carbon;

/**
 * Staff impersonation of a client's portal account.
 *
 * issue() runs in the CRM behind a session and a permission; validate() runs
 * unauthenticated in the portal, holding nothing but the token that arrived in
 * the URL. The two halves are shaped by that asymmetry - see the comments on
 * each method.
 */
class ImpersonationController extends \App\Http\Controllers\Controller
{
    /**
     * Mint a short-lived token for a client's own account and return the
     * portal URL that consumes it.
     */
    public function issue(Request $request)
    {
        $request->validate(['id' => 'required']);

        $user = ModuleConfig::userQuery('impersonation')
            ->where(
                ModuleConfig::get('impersonation.target_column', 'company_contact_id'),
                $request->input('id')
            )
            ->first();

        if (! $user) {
            return response()->json([
                'message' => ModuleConfig::message('impersonation', 'no_portal_account'),
            ], 404);
        }

        $prefix = (string) ModuleConfig::get('impersonation.token_prefix', 'impersonation-token');

        // Revoke any previous impersonation token for this client so a stale
        // one cannot linger for the rest of its window. Matched by name prefix
        // so the client's OWN login tokens are untouched - revoking those would
        // sign the client out of their own portal every time staff looked at
        // their account.
        $user->tokens()->where('name', 'like', $prefix . '%')->delete();

        // The acting staff id is encoded into the token name so audit records
        // can attribute impersonated writes to the real human: Auth::user()
        // during an impersonated request resolves to the client, not the staff
        // member. ImpersonationActor::id() recovers it.
        $tokenName = $prefix . ':' . Auth::id();

        $tokenResult = $user->createToken($tokenName);
        $plainTextToken = $tokenResult->plainTextToken;

        $expiryTime = Carbon::now()->addMinutes(
            (int) ModuleConfig::get('impersonation.expires_minutes', 60)
        );

        // forceFill: Sanctum's PersonalAccessToken does not list expires_at as
        // fillable, so a mass assignment here would be dropped and the token
        // would never expire.
        $tokenResult->accessToken
            ->forceFill(['expires_at' => $expiryTime])
            ->save();

        $portalUrl = (string) (ModuleConfig::get('impersonation.portal_url') ?? config('portal.url'));
        $portalPath = (string) ModuleConfig::get('impersonation.portal_path', '/portal');
        $redirectPath = (string) ModuleConfig::get('impersonation.redirect_path', '/impersonate');

        // Guard against a doubled path segment: the configured base URL may or
        // may not already end in the portal path.
        $url =
            rtrim($portalUrl, '/') .
            (str_ends_with($portalUrl, $portalPath) ? '' : $portalPath) .
            $redirectPath .
            '?token=' .
            $plainTextToken;

        $targetColumn = ModuleConfig::get('impersonation.target_column', 'company_contact_id');

        // A first-class audit record of who impersonated whom. The plaintext
        // token is never stored: the row is a record of the act, not a way to
        // replay it.
        $logModel = ModuleConfig::get('impersonation.log_model');

        if (is_string($logModel) && $logModel !== '' && class_exists($logModel)) {
            $logModel::create([
                'staff_user_id' => Auth::id(),
                'client_user_id' => $user->id,
                'company_contact_id' => $user->{$targetColumn} ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'token_expires_at' => $expiryTime,
            ]);
        }

        // Also recorded in the application log - again without the token, which
        // would otherwise be a live credential sitting in plain text.
        Log::info('Impersonation token issued', [
            'staff_user_id' => Auth::id(),
            'client_user_id' => $user->id,
            'company_contact_id' => $user->{$targetColumn} ?? null,
            'expires_at' => $expiryTime->toIso8601String(),
        ]);

        return response()->json(['url' => $url]);
    }

    /**
     * Exchange an impersonation token for the client it belongs to.
     *
     * Unauthenticated by necessity: the portal calls this before it has a
     * session, holding only the token from the URL.
     *
     * The name deliberately shadows ValidatesRequests::validate() - a class
     * method wins over a trait method, so nothing breaks, but never call
     * $this->validate() inside this controller; use $request->validate().
     */
    public function validate(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        Log::info('Validating impersonation token', [
            'token_length' => strlen((string) $request->token),
            'ip' => $request->ip(),
        ]);

        try {
            // findToken() verifies the plaintext token against the stored
            // SHA-256 hash. Never look the token up by its numeric id alone -
            // this endpoint is unauthenticated, so skipping the hash check
            // would let anyone enumerate token ids and retrieve user data.
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($request->token);

            if (! $tokenModel) {
                Log::warning('Impersonation token validation failed', [
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'error' => ModuleConfig::message('impersonation', 'invalid_token'),
                ], 401);
            }

            $prefix = (string) ModuleConfig::get('impersonation.token_prefix', 'impersonation-token');

            // This endpoint is for impersonation tokens only. Reject any other
            // token (a stolen portal login token, say) so it cannot be
            // laundered through here into a portal session.
            if (! str_starts_with((string) $tokenModel->name, $prefix)) {
                Log::warning('Non-impersonation token rejected at impersonation endpoint', [
                    'token_id' => $tokenModel->id,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'error' => ModuleConfig::message('impersonation', 'invalid_token'),
                ], 401);
            }

            if ($tokenModel->expires_at && now()->gt($tokenModel->expires_at)) {
                Log::warning('Token has expired', [
                    'token_id' => $tokenModel->id,
                    'expires_at' => $tokenModel->expires_at,
                    'now' => now(),
                ]);

                return response()->json([
                    'error' => ModuleConfig::message('impersonation', 'expired_token'),
                ], 401);
            }

            $user = $tokenModel->tokenable;

            if (! $user) {
                Log::warning('User not found for token', [
                    'token_id' => $tokenModel->id,
                ]);

                return response()->json([
                    'error' => ModuleConfig::message('impersonation', 'user_not_found'),
                ], 401);
            }

            // Only the relations this model actually defines. The shipped
            // default names relations belonging to the application this grew
            // out of, and eager-loading a missing one throws straight into the
            // catch-all below - which would answer a perfectly valid token with
            // "Invalid token" and nothing to explain why.
            Relations::load(
                $user,
                (array) ModuleConfig::get('impersonation.user_relations', [])
            );

            Log::info('Token validated successfully', [
                'user_id' => $user->id,
                'company_contact_id' => $user->{ModuleConfig::get('impersonation.target_column', 'company_contact_id')} ?? null,
            ]);

            // Always the whitelisted minimal payload, never the model. This
            // endpoint is unauthenticated and its token travels in a URL, so
            // serializing the full user would leak the client's live OTP hash
            // and entire portal data to anyone holding it. The portal fetches
            // full data separately over an authenticated route.
            return response()->json([
                'error' => '',
                'user' => MinimalUserPayload::build($user, 'impersonation'),
                'full_data_available' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('Exception during token validation', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Generic message only - internal exception detail must not reach
            // an unauthenticated caller.
            return response()->json([
                'error' => ModuleConfig::message('impersonation', 'invalid_token'),
            ], 401);
        }
    }
}
