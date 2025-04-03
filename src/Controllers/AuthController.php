<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

use App\Mail\GenericMail;

use App\Models\User;

use Carbon\Carbon;

class AuthController extends \App\Http\Controllers\Controller
{
    public function forgot(Request $request)
    {
        $error = '';

        $request->validate(['email' => 'required|email']);

        $checkUser = User::where('email', $request->only('email'))->count();

        if ($checkUser > 0) {
            $token = Str::random(60);

            DB::table('password_resets')->insert([
                'email' => $request->email,
                'token' => $token,
                'created_at' => Carbon::now(),
            ]);

            if (env('APP_ENV') == 'production') {
                $to = $request->only('email');
            } else {
                $to = env('MAIL_TO_DEV');
            }

            if (
                $request->has('frontend') &&
                $request->input('frontend') == 'true'
            ) {
                $url = env('FRONT_END_URL') . '/verify/' . $token;
            } else {
                $url = env('APP_URL') . '/verify/' . $token;
            }

            if ($to != '') {
                $content =
                    '<p>Click on the following <a href="' .
                    $url .
                    '">link</a> to reset your password.</p>';

                $content .=
                    '<p>If you have not requested for a password reset, please ignore this email.</p>';

                Mail::to($to)->send(
                    new GenericMail(
                        $content,
                        env('MAIL_FROM_ADDRESS'),
                        env('APP_NAME') . ' - Password Reset Request'
                    )
                );
            }
        } else {
            $error = 'The email address is not found, please try again.';
        }

        return response()->json([
            'error' => $error,
        ]);
    }

    public function reset(Request $request)
    {
        $error = '';

        $request->validate([
            'code' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $checkToken = DB::table('password_resets')
            ->where('token', $request->input('code'))
            ->count();

        if ($checkToken == 0) {
            $error =
                'The token is no longer valid, please start the password request process again.';
        } else {
            $token = DB::table('password_resets')
                ->where('token', $request->input('code'))
                ->first();

            $user = User::where('email', $token->email)->first();
            $user->password = Hash::make($request->input('password'));
            $user->save();

            DB::table('password_resets')
                ->where('email', $user->email)
                ->delete();
        }

        return response()->json([
            'error' => $error,
        ]);
    }

    public function authenticate(Request $request)
    {
        $error = '';

        // Determine if the input is an email address or a username
        $isEmail =
            filter_var($request->input('email'), FILTER_VALIDATE_EMAIL) !==
            false;

        // Prepare credentials based on the input type
        $credentials = $isEmail
            ? [
                'email' => $request->input('email'),
                'password' => $request->input('password'),
            ]
            : [
                'username' => $request->input('email'),
                'password' => $request->input('password'),
            ];

        $user = '';
        $requiresTwoFactor = false;

        if (Auth::attempt($credentials)) {
            $user = Auth::user();

            // Check if user has 2FA enabled
            if (
                method_exists($user, 'two_factor_secret') &&
                $user->two_factor_secret
            ) {
                // Store user ID in session for the 2FA challenge
                $request->session()->put('auth.two_factor.user_id', $user->id);
                $requiresTwoFactor = true;

                // Don't fully log in the user yet
                Auth::logout();
            } else {
                // User doesn't have 2FA, proceed with normal login
                if (!env('ALLOW_MULTIPLE_SESSIONS', false)) {
                    Auth::logoutOtherDevices($request->input('password'));
                }

                $user = $user->load('roles.permissions');
            }
        } else {
            $error = 'Login unsuccessful, please try again.';
            $request->session()->flash('errors');
        }

        return response()->json([
            'error' => $error,
            'previous' =>
                $request->input('location') == '/' ||
                $request->input('location') == '/login'
                    ? ''
                    : $request->input('location'),
            'user' => $user,
            'requires_two_factor' => $requiresTwoFactor,
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function login_api(Request $request)
    {
        $isEmail =
            filter_var($request->input('username'), FILTER_VALIDATE_EMAIL) !==
            false;

        // Prepare credentials based on the input type
        $credentials = $isEmail
            ? [
                'email' => $request->input('username'),
                'password' => $request->input('password'),
            ]
            : [
                'username' => $request->input('username'),
                'password' => $request->input('password'),
            ];

        $username = $request->input('username');
        $password = $request->input('password');

        if (Auth::attempt($credentials)) {
            $user = Auth::user();

            // Check if user has 2FA enabled
            if (
                method_exists($user, 'two_factor_secret') &&
                $user->two_factor_secret
            ) {
                // For API, we'll return a special response indicating 2FA is required
                return response()->json(
                    [
                        'two_factor_required' => true,
                        'user_id' => $user->id,
                    ],
                    200
                );
            }

            $token = $user->createToken('authToken');
            return response()->json(['id' => $token->plainTextToken], 200);
        } else {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
    }

    /**
     * Show the two-factor authentication challenge view.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function twoFactorChallenge(Request $request)
    {
        if (!$request->session()->has('auth.two_factor.user_id')) {
            return redirect()->route('login');
        }

        return response()->json([
            'requires_two_factor' => true,
        ]);
    }

    /**
     * Attempt to authenticate a two-factor authentication request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function twoFactorAuthenticate(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $userId = $request->session()->get('auth.two_factor.user_id');

        if (!$userId) {
            return response()->json(
                [
                    'error' => 'Invalid two-factor authentication session.',
                ],
                401
            );
        }

        $user = User::find($userId);

        if (!$user) {
            $request->session()->forget('auth.two_factor.user_id');
            return response()->json(
                [
                    'error' => 'User not found.',
                ],
                401
            );
        }

        // Check if the provided code is a recovery code
        if (str_contains($request->code, '-')) {
            // Handle recovery code
            if (
                method_exists($user, 'recoveryCodes') &&
                $this->attemptRecoveryCode($user, $request->code)
            ) {
                $this->completeLogin($request, $user);

                return response()->json([
                    'user' => $user->load('roles.permissions'),
                ]);
            }
        } else {
            // Handle regular 2FA code
            if (
                method_exists($user, 'validateTwoFactorCode') &&
                $user->validateTwoFactorCode($request->code)
            ) {
                $this->completeLogin($request, $user);

                return response()->json([
                    'user' => $user->load('roles.permissions'),
                ]);
            }
        }

        // If we reach here, the code was invalid
        return response()->json(
            [
                'error' =>
                    'The provided two-factor authentication code was invalid.',
            ],
            401
        );
    }

    /**
     * Attempt to validate a recovery code.
     *
     * @param  \App\Models\User  $user
     * @param  string  $recoveryCode
     * @return bool
     */
    protected function attemptRecoveryCode($user, $recoveryCode)
    {
        if (!method_exists($user, 'recoveryCodes')) {
            return false;
        }

        // Get the recovery codes from the user
        $recoveryCodes = $user->recoveryCodes() ?? [];

        // Find the matching recovery code
        $key = array_search($recoveryCode, $recoveryCodes);

        if ($key !== false) {
            // Remove the used recovery code
            unset($recoveryCodes[$key]);

            // Update the user's recovery codes
            $user->replaceRecoveryCodes($recoveryCodes);

            return true;
        }

        return false;
    }

    /**
     * Complete the login process after successful 2FA.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\User  $user
     * @return void
     */
    protected function completeLogin(Request $request, $user)
    {
        // Clear the 2FA session
        $request->session()->forget('auth.two_factor.user_id');

        // Log in the user
        Auth::login($user);

        // Handle multiple sessions if needed
        if (!env('ALLOW_MULTIPLE_SESSIONS', false)) {
            Auth::logoutOtherDevices($request->input('password'));
        }

        // Regenerate the session
        $request->session()->regenerate();
    }

    /**
     * Handle two-factor authentication for API requests.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function twoFactorAuthenticateApi(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'code' => 'required|string',
        ]);

        $user = User::find($request->user_id);

        if (!$user) {
            return response()->json(
                [
                    'error' => 'User not found.',
                ],
                401
            );
        }

        // Check if the provided code is a recovery code
        if (str_contains($request->code, '-')) {
            // Handle recovery code
            if (
                method_exists($user, 'recoveryCodes') &&
                $this->attemptRecoveryCode($user, $request->code)
            ) {
                $token = $user->createToken('authToken');
                return response()->json(['id' => $token->plainTextToken], 200);
            }
        } else {
            // Handle regular 2FA code
            if (
                method_exists($user, 'validateTwoFactorCode') &&
                $user->validateTwoFactorCode($request->code)
            ) {
                $token = $user->createToken('authToken');
                return response()->json(['id' => $token->plainTextToken], 200);
            }
        }

        // If we reach here, the code was invalid
        return response()->json(
            [
                'error' =>
                    'The provided two-factor authentication code was invalid.',
            ],
            401
        );
    }
}
