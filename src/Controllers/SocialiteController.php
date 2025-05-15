<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Role;
use App\Models\User;

class SocialiteController extends \App\Http\Controllers\Controller
{
    /**
     * Redirect the user to the provider authentication page.
     *
     * @param string $provider
     * @return \Illuminate\Http\RedirectResponse
     */
    public function redirectToProvider($provider)
    {
        // Validate the provider
        if (!in_array($provider, ['google', 'azure', 'facebook', 'apple'])) {
            return redirect(
                env('FRONTEND_URL', '/') . '/login?error=Invalid provider'
            );
        }

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle the provider callback.
     *
     * @param string $provider
     * @return \Illuminate\Http\RedirectResponse
     */
    public function handleProviderCallback($provider)
    {
        try {
            $socialUser = Socialite::driver($provider)->user();

            // Check if the user already exists based on provider ID
            $user = User::where('provider_id', $socialUser->getId())
                ->where('provider', $provider)
                ->first();

            if (!$user) {
                // Check if the user exists by email
                $user = User::where('email', $socialUser->getEmail())->first();

                if (!$user) {
                    // If the user doesn't exist, create a new user account
                    $user = User::create([
                        'name' => $socialUser->getName(),
                        'email' => $socialUser->getEmail(),
                        'username' => $this->generateUsername(
                            $socialUser->getNickname() ?? $socialUser->getName()
                        ),
                        'password' => Hash::make(Str::random(24)), // Random password for security
                        'provider' => $provider,
                        'provider_id' => $socialUser->getId(),
                        'avatar' => $socialUser->getAvatar(),
                        'email_verified_at' => now(), // Mark as verified since provider has verified
                    ]);

                    // Assign default role if configured
                    if (env('SOCIALITE_DEFAULT_ROLE')) {
                        $user->syncRoles([env('SOCIALITE_DEFAULT_ROLE')]);
                    }
                } else {
                    // Update existing user with provider details
                    $user->update([
                        'provider' => $provider,
                        'provider_id' => $socialUser->getId(),
                        'avatar' => $socialUser->getAvatar() ?? $user->avatar,
                    ]);
                }
            }

            // Store provider tokens
            $user->update([
                'provider_token' => $socialUser->token,
                'provider_refresh_token' => $socialUser->refreshToken ?? null,
            ]);

            // Log in the user
            Auth::login($user);

            // Generate a token for API access
            $token = $user->createToken('auth-token')->plainTextToken;

            // Redirect to the frontend with the token
            $frontendUrl = env('FRONTEND_URL', '/');
            $redirectUrl = "{$frontendUrl}/auth/callback?token={$token}&provider={$provider}";

            return redirect($redirectUrl);
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('OAuth error: ' . $e->getMessage());

            // Redirect to login with error message
            $frontendUrl = env('FRONTEND_URL', '/');
            return redirect(
                "{$frontendUrl}/login?error=Authentication failed with {$provider}"
            );
        }
    }

    /**
     * Generate a unique username based on the provider nickname or name.
     *
     * @param string $name
     * @return string
     */
    protected function generateUsername($name)
    {
        // Convert to lowercase and replace spaces with underscores
        $username = Str::slug($name, '_');

        // Check if username exists
        $count = User::where('username', 'like', $username . '%')->count();

        // If username exists, append a number
        return $count ? $username . '_' . ($count + 1) : $username;
    }

    /**
     * Get available OAuth providers.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProviders(): JsonResponse
    {
        // Get available providers from config
        $providers = array_filter([
            'google' => config('services.google.client_id') ? true : false,
            'azure' => config('services.azure.client_id') ? true : false,
            'facebook' => config('services.facebook.client_id') ? true : false,
            'apple' => config('services.apple.client_id') ? true : false,
        ]);

        return response()->json([
            'providers' => array_keys(array_filter($providers)),
            'urls' => [
                'google' => url('/auth/google'),
                'azure' => url('/auth/azure'),
                'facebook' => url('/auth/facebook'),
                'apple' => url('/auth/apple'),
            ],
        ]);
    }

    /**
     * Check authentication status.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAuthStatus(Request $request): JsonResponse
    {
        // Since we've applied auth:sanctum middleware to this route,
        // we can be sure that $request->user() will return the authenticated user
        $user = $request->user();

        return response()->json([
            'authenticated' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username ?? null,
                'provider' => $user->provider ?? null,
                'avatar' => $user->avatar ?? null,
            ],
        ]);
    }
}
