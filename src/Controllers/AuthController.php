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
use Laravel\Fortify\Fortify;

use App\Mail\GenericMail;

use App\Models\User;
use Visnsstudio\VisnsPackages\Models\TwoFactorRememberToken;

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

        // First, find the user by email or username
        if ($isEmail) {
            $user = \App\Models\User::where(
                'email',
                $request->input('email')
            )->first();
        } else {
            $user = \App\Models\User::where(
                'username',
                $request->input('email')
            )->first();
        }

        // If user exists, check if disabled and then check password
        if ($user && $user->disabled) {
            $error =
                'Your account has been disabled. Please contact the administrator.';
            $request->session()->flash('errors');
            $user = ''; // Reset user if account is disabled
        } elseif (
            $user &&
            Hash::check($request->input('password'), $user->password)
        ) {
            // Check if user has 2FA enabled and confirmed
            if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
                // Check if there's a valid remember token for this device
                $deviceIdentifier = $this->getDeviceIdentifier($request);
                $rememberToken = TwoFactorRememberToken::findValidTokenByDevice(
                    $user,
                    $deviceIdentifier
                );

                if ($rememberToken) {
                    // User has a valid remember token, skip 2FA
                    Auth::login($user);

                    if (!env('ALLOW_MULTIPLE_SESSIONS', false)) {
                        Auth::logoutOtherDevices($request->input('password'));
                    }

                    $user = $user->load('roles.permissions');
                } else {
                    // Check if we're in production
                    if (env('APP_ENV') == 'production') {
                        // In production, require 2FA
                        $request
                            ->session()
                            ->put('auth.two_factor.user_id', $user->id);
                        $requiresTwoFactor = true;
                    } else {
                        // In non-production, skip 2FA and log the user in
                        Auth::login($user);

                        if (!env('ALLOW_MULTIPLE_SESSIONS', false)) {
                            Auth::logoutOtherDevices(
                                $request->input('password')
                            );
                        }

                        $user = $user->load('roles.permissions');
                        $requiresTwoFactor = false;
                    }
                }
            } else {
                // User doesn't have 2FA, proceed with normal login
                Auth::login($user);

                if (!env('ALLOW_MULTIPLE_SESSIONS', false)) {
                    Auth::logoutOtherDevices($request->input('password'));
                }

                $user = $user->load('roles.permissions');
            }
        } else {
            $error = 'Login unsuccessful, please try again.';
            $request->session()->flash('errors');
            $user = ''; // Reset user if authentication failed
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

    /**
     * API logout endpoint.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout_api(Request $request)
    {
        // Revoke the user's current token
        if ($request->user()) {
            $request
                ->user()
                ->currentAccessToken()
                ->delete();
        }

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Register a new user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        try {
            // Validate the request data
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'username' => 'required|string|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
            ]);

            // Create the user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'username' => $request->username,
                'password' => Hash::make($request->password),
                'email_verified_at' => null, // Will be verified later
            ]);

            // Assign default role if configured
            if (env('DEFAULT_USER_ROLE')) {
                if (method_exists($user, 'syncRoles')) {
                    $user->syncRoles([env('DEFAULT_USER_ROLE')]);
                }
            }

            // Log the user in
            Auth::login($user);

            // Create a token for API access
            $token = $user->createToken('authToken');

            // Return the token
            return response()->json(
                [
                    'id' => $token->plainTextToken,
                    'user' => $user->load('roles.permissions'),
                ],
                201
            );
        } catch (ValidationException $e) {
            // Return validation errors as JSON
            return response()->json(
                [
                    'message' => 'The given data was invalid.',
                    'errors' => $e->errors(),
                ],
                422
            );
        } catch (\Exception $e) {
            // Handle other exceptions
            return response()->json(
                [
                    'message' => 'An error occurred during registration.',
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    public function login_api(Request $request)
    {
        $isEmail =
            filter_var($request->input('username'), FILTER_VALIDATE_EMAIL) !==
            false;

        // First, find the user by email or username
        if ($isEmail) {
            $user = \App\Models\User::where(
                'email',
                $request->input('username')
            )->first();
        } else {
            $user = \App\Models\User::where(
                'username',
                $request->input('username')
            )->first();
        }

        // If user exists, check if disabled and then check password
        if ($user && $user->disabled) {
            return response()->json(
                [
                    'error' =>
                        'Your account has been disabled. Please contact the administrator.',
                ],
                401
            );
        } elseif (
            $user &&
            Hash::check($request->input('password'), $user->password)
        ) {
            // Check if user has 2FA enabled and confirmed
            if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
                // Check if there's a valid remember token for this device
                $deviceIdentifier =
                    $request->input('device_identifier') ?:
                    $this->getDeviceIdentifier($request);
                $rememberToken = TwoFactorRememberToken::findValidTokenByDevice(
                    $user,
                    $deviceIdentifier
                );

                if ($rememberToken) {
                    // User has a valid remember token, skip 2FA
                    Auth::login($user);
                    $token = $user->createToken('authToken');
                    return response()->json(
                        ['id' => $token->plainTextToken],
                        200
                    );
                } else {
                    // For API, check if we're in production before requiring 2FA
                    if (env('APP_ENV') == 'production') {
                        // Return a special response indicating 2FA is required
                        return response()->json(
                            [
                                'two_factor_required' => true,
                                'user_id' => $user->id,
                            ],
                            200
                        );
                    } else {
                        // Skip 2FA in non-production environments
                        Auth::login($user);
                        $token = $user->createToken('authToken');
                        return response()->json(
                            ['id' => $token->plainTextToken],
                            200
                        );
                    }
                }
            }

            // User doesn't have 2FA, proceed with normal login and token creation
            Auth::login($user);
            $token = $user->createToken('authToken');
            return response()->json(['id' => $token->plainTextToken], 200);
        }

        // Authentication failed
        return response()->json(['error' => 'Unauthenticated'], 401);
    }

    /**
     * Attempt to authenticate a two-factor authentication request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function twoFactorAuthenticate(Request $request)
    {
        // If not in production, skip 2FA validation and return success
        if (env('APP_ENV') != 'production') {
            // Get the user ID from the session
            $userId = $request->session()->get('auth.two_factor.user_id');

            if ($userId) {
                $user = User::find($userId);
                if ($user) {
                    // Complete the login process
                    $this->completeLogin($user);
                    return response()->json([
                        'user' => $user->load('roles.permissions'),
                    ]);
                }
            }

            // If we can't find the user, return an error
            return response()->json(
                [
                    'error' => 'Invalid two-factor authentication session.',
                ],
                200
            );
        }

        // Normal 2FA validation for production
        $request->validate([
            'code' => 'required|string',
            'remember' => 'sometimes|boolean',
        ]);

        $userId = $request->session()->get('auth.two_factor.user_id');

        if (!$userId) {
            return response()->json(
                [
                    'error' => 'Invalid two-factor authentication session.',
                ],
                200
            );
        }

        $user = User::find($userId);

        if (!$user) {
            $request->session()->forget('auth.two_factor.user_id');
            return response()->json(
                [
                    'error' => 'User not found.',
                ],
                200
            );
        }

        // Use the UserController to validate the 2FA code
        $userController = new \Visnsstudio\VisnsPackages\Controllers\UserController();

        if ($userController->validateTwoFactorCode($user, $request->code)) {
            // Complete the login process
            $this->completeLogin($user);

            // If remember is true, create a remember token for this device
            if ($request->input('remember', false)) {
                $deviceIdentifier = $this->getDeviceIdentifier($request);
                TwoFactorRememberToken::createToken($user, $deviceIdentifier);
            }

            return response()->json([
                'user' => $user->load('roles.permissions'),
            ]);
        }

        // If we reach here, the code was invalid
        return response()->json(
            [
                'error' =>
                    'The provided two-factor authentication code was invalid.',
            ],
            200
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
     * @param  \App\Models\User  $user
     * @return void
     */
    protected function completeLogin($user)
    {
        // Clear the 2FA session
        session()->forget('auth.two_factor.user_id');

        // Log in the user
        Auth::login($user);

        // Handle multiple sessions if needed
        // Note: We can't logout other devices here because we don't have the password
        // in the 2FA verification request. This is handled in the initial login step.

        // Regenerate the session
        session()->regenerate();
    }

    /**
     * Handle two-factor authentication for API requests.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function twoFactorAuthenticateApi(Request $request)
    {
        // If not in production, skip 2FA validation and return success
        if (env('APP_ENV') != 'production') {
            $request->validate([
                'user_id' => 'required|exists:users,id',
            ]);

            $user = User::find($request->user_id);

            if (!$user) {
                return response()->json(
                    [
                        'error' => 'User not found.',
                    ],
                    200
                );
            }

            // Skip 2FA validation and create token
            $token = $user->createToken('authToken');
            return response()->json(['id' => $token->plainTextToken], 200);
        }

        // Normal 2FA validation for production
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'code' => 'required|string',
            'remember' => 'sometimes|boolean',
            'device_identifier' => 'sometimes|string',
        ]);

        $user = User::find($request->user_id);

        if (!$user) {
            return response()->json(
                [
                    'error' => 'User not found.',
                ],
                200
            );
        }

        // Use the UserController to validate the 2FA code
        $userController = new \Visnsstudio\VisnsPackages\Controllers\UserController();

        if ($userController->validateTwoFactorCode($user, $request->code)) {
            // If remember is true, create a remember token for this device
            if ($request->input('remember', false)) {
                $deviceIdentifier =
                    $request->input('device_identifier') ?:
                    $this->getDeviceIdentifier($request);
                TwoFactorRememberToken::createToken($user, $deviceIdentifier);
            }

            // Create a token for API access
            $token = $user->createToken('authToken');
            return response()->json(['id' => $token->plainTextToken], 200);
        }

        // If we reach here, the code was invalid
        return response()->json(
            [
                'error' =>
                    'The provided two-factor authentication code was invalid.',
            ],
            200
        );
    }

    /**
     * Get a unique identifier for the current device.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function getDeviceIdentifier(Request $request)
    {
        // Create a unique identifier based on user agent and IP address
        $userAgent = $request->header('User-Agent', 'unknown');
        $ip = $request->ip();

        // Create a hash of these values
        return hash('sha256', $userAgent . $ip);
    }
}
