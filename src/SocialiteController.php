<?php

namespace Visnsstudio\VisnsPackages;

use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\Role;
use App\Models\User;

class SocialiteController extends \App\Http\Controllers\Controller
{
    public function redirectToProvider($provider)
    {
        return Socialite::driver($provider)->redirect();
    }

    public function handleProviderCallback($provider)
    {
        try {
            $socialUser = Socialite::driver($provider)->user();

            // Check if the user already exists based on the email
            $user = User::where("email", $socialUser->getEmail())->first();

            if (!$user) {
                // If the user doesn't exist, create a new user account
                $user = User::create([
                    "name" => $socialUser->getName(),
                    "email" => $socialUser->getEmail(),
                    "password" => Hash::make($socialUser->getId()),
                    // You may need to adjust other fields or validations as per your User model
                ]);

                if (getenv("SOCIALITE_DEFAULT_ROLE")) {
                    $user->syncRoles([env("SOCIALITE_DEFAULT_ROLE")]);
                }
            }

            // Link the social account with the existing user (optional)
            $user->update([
                "provider" => $provider,
                "provider_id" => $socialUser->getId(),
                "provider_token" => $socialUser->token,
                "provider_refresh_token" => $socialUser->refreshToken,
            ]);

            // Log in the user using Laravel's authentication
            Auth::login($user);

            return redirect("/"); // Redirect the user to the desired page after successful login.
        } catch (\Exception $e) {
            return redirect("/login")->with(
                "error",
                "OAuth authentication failed."
            );
        }
    }
}
