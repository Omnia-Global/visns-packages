<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

use Visnsstudio\VisnsPackages\Auth\DefaultUserResolver;
use Visnsstudio\VisnsPackages\Auth\TwoFactorCodeManager;
use Visnsstudio\VisnsPackages\Models\TwoFactorRememberToken;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

use Carbon\Carbon;

/**
 * The package login flow.
 *
 * Two rules govern every change made here:
 *
 *  - With a stock config this controller behaves exactly as it always has. Every
 *    extension point below defaults to the historical behaviour, so an
 *    application upgrading the package without publishing the config sees no
 *    difference at all.
 *
 *  - Nothing reads the environment directly. An environment read returns null
 *    once `config:cache` has run, which is exactly the state production runs in,
 *    so such a read works in development and silently evaporates on deploy.
 *    Everything comes from config('visns-packages.auth.*') via ModuleConfig.
 */
class AuthController extends \App\Http\Controllers\Controller
{
    public function forgot(Request $request)
    {
        $error = '';

        $request->validate(['email' => 'required|email']);

        // Which account is this address for? The built-in resolver matches the
        // `email` column, which is all this endpoint has ever done; an
        // application whose accounts are reachable by more than one address
        // configures its own.
        $user = $this->resolveResetUser((string) $request->input('email'), $request);

        if ($user) {
            $token = Str::random(60);

            DB::table('password_resets')->insert([
                // The typed address by default, which is what this package has
                // always stored. An application with a resolver wants the
                // RESOLVED account's address instead: the two differ precisely
                // when the resolver looked past what was typed, and a row keyed
                // on the typed address is then one reset() can never match an
                // account back to.
                'email' => ModuleConfig::get('auth.reset_key_by_resolved_email', false)
                    ? $user->email
                    : $request->email,
                'token' => $token,
                'created_at' => Carbon::now(),
            ]);

            if (app()->environment('production')) {
                $to = $request->email;
            } else {
                // Outside production every reset mail is funnelled to one
                // address so a copied production database cannot mail real
                // clients.
                $to = ModuleConfig::get('auth.mail_to_dev');
            }

            $url = $this->buildResetUrl($user, $token, $request);

            if ($to != '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $content =
                    '<p>Click on the following <a href="' .
                    $url .
                    '">link</a> to reset your password.</p>';

                $content .=
                    '<p>If you have not requested for a password reset, please ignore this email.</p>';

                $mailable = $this->buildResetMailable($content);

                if ($mailable !== null) {
                    Mail::to($to)->send($mailable);
                }
            }
        } else {
            $error = ModuleConfig::message(
                'auth',
                'email_not_found',
                'The email address is not found, please try again.'
            );
        }

        return response()->json([
            'error' => $error,
        ]);
    }

    /**
     * Build the password-reset mailable.
     *
     * Three tiers, in order:
     *   1. auth.reset_mail_factory - fn ($content, $subject): Mailable.
     *   2. auth.reset_mailable     - new $mailable($content, $subject, []).
     *   3. Neither set             - \App\Mail\GenericMail($content, $from, $subject).
     *
     * Tier 3 looks wrong and is kept on purpose: the package has always passed
     * the from-address as the second argument, and applications have built their
     * GenericMail around that. Changing it would silently retitle every reset
     * email in every existing installation. Applications with a sane signature
     * use tier 1 or 2.
     *
     * The class is referenced fully-qualified and guarded with class_exists so
     * the package no longer hard-depends on an application class merely to be
     * loaded; with nothing configured and no GenericMail present the send is
     * skipped and the endpoint answers exactly as before.
     *
     * @return \Illuminate\Contracts\Mail\Mailable|null
     */
    protected function buildResetMailable(string $content)
    {
        $subject =
            ModuleConfig::get('auth.reset_subject')
                ?: ModuleConfig::get('auth.app_name') . ' - Password Reset Request';

        if ($factory = ModuleConfig::callable('auth.reset_mail_factory')) {
            return $factory($content, $subject);
        }

        $mailable = ModuleConfig::get('auth.reset_mailable');

        if (is_string($mailable) && $mailable !== '' && class_exists($mailable)) {
            return new $mailable($content, $subject, []);
        }

        if (class_exists(\App\Mail\GenericMail::class)) {
            return new \App\Mail\GenericMail(
                $content,
                ModuleConfig::get('auth.mail_from_address'),
                $subject
            );
        }

        return null;
    }

    /**
     * Which account a password-reset address belongs to.
     *
     * Shared by forgot() (resolving what the user typed) and reset() (resolving
     * the address stored on the token row) so the two halves of one reset can
     * never disagree about which account is being changed.
     *
     * @return object|null
     */
    protected function resolveResetUser(string $email, Request $request)
    {
        if ($resolver = ModuleConfig::callable('auth.reset_user_resolver')) {
            return $resolver($email, $request);
        }

        return ModuleConfig::userQuery('auth')->where('email', $email)->first();
    }

    /**
     * The link that goes in the reset email.
     *
     * The default is the historical build and stays byte-identical: the
     * `frontend=true` flag picks front_end_url over app_url, and the token is a
     * path segment. Anything else - a query-string token, a portal host, a
     * per-account path - is the application's to construct.
     */
    protected function buildResetUrl($user, string $token, Request $request): string
    {
        if ($builder = ModuleConfig::callable('auth.reset_url_builder')) {
            return (string) $builder($user, $token, $request);
        }

        $base =
            $request->has('frontend') && $request->input('frontend') == 'true'
                ? ModuleConfig::get('auth.front_end_url')
                : ModuleConfig::get('auth.app_url');

        return $base . '/verify/' . $token;
    }

    /**
     * Fire the after-reset hooks.
     *
     * They receive the plaintext password because a hook's usual job is to
     * mirror it onto another record that hashes it its own way. That makes a
     * hook a place where a plaintext credential briefly exists - it must not be
     * logged, returned or persisted unhashed.
     */
    protected function runAfterResetHooks($user, string $plainPassword): void
    {
        foreach (ModuleConfig::callables('auth.after_reset_hooks') as $hook) {
            $hook($user, $plainPassword);
        }
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
            $error = ModuleConfig::message(
                'auth',
                'invalid_reset_token',
                'The token is no longer valid, please start the password request process again.'
            );
        } else {
            $token = DB::table('password_resets')
                ->where('token', $request->input('code'))
                ->first();

            // Resolved through the same resolver forgot() used, so the account
            // being changed is the one the token was issued for.
            $user = $this->resolveResetUser((string) $token->email, $request);

            if (! $user) {
                // A row whose address no longer resolves to anything - a
                // renamed account, or a resolver that has since narrowed.
                // Previously this dereferenced null and fatalled; answering
                // with the same message a stale token gets is both truthful
                // and useless to someone probing for valid addresses.
                $error = ModuleConfig::message(
                    'auth',
                    'invalid_reset_token',
                    'The token is no longer valid, please start the password request process again.'
                );
            } else {
                $plainPassword = (string) $request->input('password');

                $user->password = Hash::make($plainPassword);
                $user->save();

                $this->runAfterResetHooks($user, $plainPassword);

                // Keyed on the ROW's address, not the resolved account's. They
                // are the same thing unless a resolver looked past the typed
                // address, and in that case deleting by the account's own
                // address would leave the spent token alive and reusable.
                DB::table('password_resets')
                    ->where('email', $token->email)
                    ->delete();
            }
        }

        return response()->json([
            'error' => $error,
        ]);
    }

    public function authenticate(Request $request)
    {
        $error = '';
        $requiresTwoFactor = false;

        // '' rather than null on failure: the historical response shape, which
        // the front end distinguishes from a user object.
        $payload = '';

        $user = $this->resolveUser((string) $request->input('email'), $request);

        // Gates normally run after the password check so they cannot be used to
        // probe which accounts exist. An application that must reject an account
        // before the password is even looked at opts in explicitly.
        if ($user && $this->gatesRunFirst()) {
            if ($gate = $this->runPreLoginGates($user, $request)) {
                return $gate;
            }
        }

        if ($user && $user->disabled) {
            $error = ModuleConfig::message(
                'auth',
                'account_disabled',
                'Your account has been disabled. Please contact the administrator.'
            );
            $request->session()->flash('errors');
        } elseif (
            $user &&
            Hash::check($request->input('password'), $user->password)
        ) {
            if (! $this->gatesRunFirst()) {
                if ($gate = $this->runPreLoginGates($user, $request)) {
                    return $gate;
                }
            }

            if ($this->usesCodeDriver()) {
                $manager = $this->twoFactorManager();

                if ($manager->shouldChallenge($user, $request)) {
                    try {
                        $manager->send($user, $manager->issue($user));
                    } catch (\Throwable $e) {
                        // A code that never left the building must not let the
                        // login through: refuse, and say so.
                        return response()->json([
                            'error' => ModuleConfig::message(
                                'auth',
                                'two_factor_send_failed',
                                'The verification code could not be sent, please try again.'
                            ),
                            'previous' => $this->filteredPrevious(
                                $request->input('location')
                            ),
                            'user' => '',
                            'requires_two_factor' => false,
                            'csrf_token' => $this->csrfToken($request),
                        ]);
                    }

                    $request->session()->put('auth.two_factor.user_id', $user->id);
                    // Captured here, while the request is still the one that
                    // proved the password. The challenge POST cannot widen it.
                    $this->stashRemember($request, $user);

                    return response()->json([
                        'error' => '',
                        'previous' => $this->filteredPrevious(
                            $request->input('location')
                        ),
                        'user' => null,
                        'requires_two_factor' => true,
                        'csrf_token' => $this->csrfToken($request),
                    ]);
                }

                $this->loginToSession(
                    $user,
                    $request,
                    $this->wantsRemember($request, $user)
                );
                $payload = $user->load('roles.permissions');
            } elseif ($user->two_factor_secret && $user->two_factor_confirmed_at) {
                // Check if there's a valid remember token for this device
                $deviceIdentifier = $this->getDeviceIdentifier($request);
                $rememberToken = TwoFactorRememberToken::findValidTokenByDevice(
                    $user,
                    $deviceIdentifier
                );

                if ($rememberToken) {
                    // User has a valid remember token, skip 2FA
                    $this->loginToSession(
                        $user,
                        $request,
                        $this->wantsRemember($request, $user)
                    );

                    $payload = $user->load('roles.permissions');
                } else {
                    // 2FA is only ever demanded in production: outside it there
                    // is no shared authenticator and no way to self-serve.
                    if (app()->environment('production')) {
                        $request
                            ->session()
                            ->put('auth.two_factor.user_id', $user->id);
                        $this->stashRemember($request, $user);
                        $requiresTwoFactor = true;

                        // The user model still goes back on a TOTP challenge -
                        // unloaded, as it always has - because the challenge
                        // screen renders the account it is challenging. The
                        // code driver deliberately returns null instead: there
                        // the account is identified by a code sent out of band,
                        // so echoing the record would hand an unauthenticated
                        // caller a user lookup.
                        $payload = $user;
                    } else {
                        $this->loginToSession(
                            $user,
                            $request,
                            $this->wantsRemember($request, $user)
                        );

                        $payload = $user->load('roles.permissions');
                        $requiresTwoFactor = false;
                    }
                }
            } else {
                // User doesn't have 2FA, proceed with normal login
                $this->loginToSession(
                    $user,
                    $request,
                    $this->wantsRemember($request, $user)
                );

                $payload = $user->load('roles.permissions');
            }
        } else {
            $error = ModuleConfig::message(
                'auth',
                'login_failed',
                'Login unsuccessful, please try again.'
            );
            $request->session()->flash('errors');
        }

        return response()->json([
            'error' => $error,
            'previous' => $this->filteredPrevious($request->input('location')),
            'user' => $payload,
            'requires_two_factor' => $requiresTwoFactor,
            'csrf_token' => $this->csrfToken($request),
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
        // Revoke only the token used for this request, not every token the user
        // holds. Guard on PersonalAccessToken: a stateful-SPA (session) request
        // yields a TransientToken, which has no delete() and used to fatal here.
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(
            ModuleConfig::get('auth.logout_response', [
                'message' => 'Successfully logged out',
            ])
        );
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

            // Configured model rather than the package model. An application
            // pointing user_model at its own class must make sure name/username
            // are fillable there, or mass assignment will drop them.
            $model = ModuleConfig::userModel('auth');

            $user = $model::create([
                'name' => $request->name,
                'email' => $request->email,
                'username' => $request->username,
                'password' => Hash::make($request->password),
                'email_verified_at' => null, // Will be verified later
            ]);

            // Assign default role if configured
            if ($defaultRole = ModuleConfig::get('auth.default_user_role')) {
                if (method_exists($user, 'syncRoles')) {
                    $user->syncRoles([$defaultRole]);
                }
            }

            // Log the user in
            Auth::login($user);

            // Create a token for API access
            $token = $user->createToken('authToken');

            $this->runPostLoginHooks($user, $request);

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
                    'message' => ModuleConfig::message(
                        'auth',
                        'invalid_data',
                        'The given data was invalid.'
                    ),
                    'errors' => $e->errors(),
                ],
                422
            );
        } catch (\Exception $e) {
            // Handle other exceptions
            return response()->json(
                [
                    'message' => ModuleConfig::message(
                        'auth',
                        'registration_failed',
                        'An error occurred during registration.'
                    ),
                    'error' => $e->getMessage(),
                ],
                500
            );
        }
    }

    public function login_api(Request $request)
    {
        $user = $this->resolveUser((string) $request->input('username'), $request);

        if ($user && $this->gatesRunFirst()) {
            if ($gate = $this->runPreLoginGates($user, $request)) {
                return $gate;
            }
        }

        // If user exists, check if disabled and then check password
        if ($user && $user->disabled) {
            return response()->json(
                [
                    'error' => ModuleConfig::message(
                        'auth',
                        'account_disabled',
                        'Your account has been disabled. Please contact the administrator.'
                    ),
                ],
                401
            );
        } elseif (
            $user &&
            Hash::check($request->input('password'), $user->password)
        ) {
            if (! $this->gatesRunFirst()) {
                if ($gate = $this->runPreLoginGates($user, $request)) {
                    return $gate;
                }
            }

            if ($this->usesCodeDriver()) {
                $manager = $this->twoFactorManager();

                if ($manager->shouldChallenge($user, $request)) {
                    try {
                        $manager->send($user, $manager->issue($user));
                    } catch (\Throwable $e) {
                        return response()->json(
                            [
                                'error' => ModuleConfig::message(
                                    'auth',
                                    'two_factor_send_failed',
                                    'The verification code could not be sent, please try again.'
                                ),
                            ],
                            500
                        );
                    }

                    // Same envelope the TOTP path returns, so the existing API
                    // challenge endpoint completes either driver.
                    return response()->json(
                        [
                            'two_factor_required' => true,
                            'user_id' => $user->id,
                        ],
                        200
                    );
                }

                return response()->json(
                    ['id' => $this->issueApiToken($user, $request)],
                    200
                );
            }

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
                    return response()->json(
                        ['id' => $this->issueApiToken($user, $request)],
                        200
                    );
                } else {
                    // For API, check if we're in production before requiring 2FA
                    if (app()->environment('production')) {
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
                        return response()->json(
                            ['id' => $this->issueApiToken($user, $request)],
                            200
                        );
                    }
                }
            }

            // User doesn't have 2FA, proceed with normal login and token creation
            return response()->json(
                ['id' => $this->issueApiToken($user, $request)],
                200
            );
        }

        // Authentication failed
        return response()->json(
            [
                'error' => ModuleConfig::message(
                    'auth',
                    'unauthenticated',
                    'Unauthenticated'
                ),
            ],
            401
        );
    }

    /**
     * Attempt to authenticate a two-factor authentication request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function twoFactorAuthenticate(Request $request)
    {
        if ($this->usesCodeDriver()) {
            return $this->twoFactorAuthenticateWithCode($request);
        }

        // If not in production, skip 2FA validation and return success
        if (! app()->environment('production')) {
            // Get the user ID from the session
            $userId = $request->session()->get('auth.two_factor.user_id');

            if ($userId) {
                $user = $this->findUser($userId);
                if ($user) {
                    // Complete the login process
                    $this->completeLogin($user);
                    $this->runPostLoginHooks($user, $request);
                    return response()->json([
                        'user' => $user->load('roles.permissions'),
                    ]);
                }
            }

            // If we can't find the user, return an error
            return response()->json(
                [
                    'error' => $this->invalidSessionMessage(),
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
                    'error' => $this->invalidSessionMessage(),
                ],
                200
            );
        }

        $user = $this->findUser($userId);

        if (!$user) {
            $request->session()->forget('auth.two_factor.user_id');
            $request->session()->forget(self::REMEMBER_SESSION_KEY);
            return response()->json(
                [
                    'error' => $this->userNotFoundMessage(),
                ],
                200
            );
        }

        if ($this->validateTotpCode($user, (string) $request->code)) {
            // Complete the login process
            $this->completeLogin($user);

            // If remember is true, create a remember token for this device
            if ($request->input('remember', false)) {
                $deviceIdentifier = $this->getDeviceIdentifier($request);
                TwoFactorRememberToken::createToken($user, $deviceIdentifier);
            }

            $this->runPostLoginHooks($user, $request);

            return response()->json([
                'user' => $user->load('roles.permissions'),
            ]);
        }

        // If we reach here, the code was invalid. 200, not 401: the front end
        // has always read `error` off a 200 body here, and a 401 would trip the
        // global "session expired" interceptor and bounce the user off the
        // challenge screen.
        return response()->json(
            [
                'error' => $this->invalidCodeMessage(),
            ],
            200
        );
    }

    /**
     * The code-driver counterpart of twoFactorAuthenticate().
     *
     * Kept separate rather than woven into the TOTP branch so the TOTP flow
     * stays exactly what it was; the two share nothing but the session key.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function twoFactorAuthenticateWithCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'previous_url' => 'sometimes|nullable|string',
            'remember' => 'sometimes|boolean',
        ]);

        $previous = $this->filteredPrevious($request->input('previous_url'));

        $userId = $request->session()->get('auth.two_factor.user_id');

        if (!$userId) {
            return response()->json(
                [
                    'error' => $this->invalidSessionMessage(),
                    'user' => null,
                    'previous' => $previous,
                ],
                200
            );
        }

        $user = $this->findUser($userId);

        if (!$user) {
            $request->session()->forget('auth.two_factor.user_id');
            $request->session()->forget(self::REMEMBER_SESSION_KEY);

            return response()->json(
                [
                    'error' => $this->userNotFoundMessage(),
                    'user' => null,
                    'previous' => $previous,
                ],
                200
            );
        }

        $manager = $this->twoFactorManager();
        $result = $manager->verify($user, (string) $request->input('code'));

        if ($result !== true) {
            // 200 with an `error` body, matching the TOTP endpoint above.
            return response()->json(
                [
                    'error' => ModuleConfig::message(
                        'auth',
                        $result,
                        $this->invalidCodeMessage()
                    ),
                    'user' => null,
                    'previous' => $previous,
                ],
                200
            );
        }

        $manager->consume($user);

        // Remember ME: the choice made at the original login, taken from the
        // session. NOT $request->input('remember') - by this point the caller
        // is only half authenticated, so its request body must not be able to
        // extend the session's lifetime.
        Auth::login($user, $this->takeStashedRemember($user));

        session()->forget('auth.two_factor.user_id');

        // No session rotation of our own: Auth::login() has already done it.
        // See csrfToken() for what that means for the page that called this.

        // Remember this DEVICE - a different feature that happens to share the
        // field name, and one the challenge request legitimately owns: it is
        // about the browser answering the challenge, so it can only be decided
        // here. Opt-in for the code driver, because an SMS code is a possession
        // factor tied to the phone, not to this browser.
        if ($manager->remembersDevices() && $request->input('remember', false)) {
            TwoFactorRememberToken::createToken(
                $user,
                $this->getDeviceIdentifier($request)
            );
        }

        $this->runPostLoginHooks($user, $request);

        return response()->json([
            'error' => '',
            'user' => $user->load('roles.permissions'),
            'previous' => $previous,
            'csrf_token' => $this->csrfToken($request),
        ]);
    }

    /**
     * Re-send the login code the user is currently being challenged for.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function twoFactorResend(Request $request)
    {
        $manager = $this->twoFactorManager();
        $userId = $request->session()->get('auth.two_factor.user_id');

        // No code channel, or no challenge in flight: there is nothing to
        // re-send, and saying so is safer than issuing a code nobody asked for.
        if (! $this->usesCodeDriver() || ! $userId || $manager->trigger() === 'never') {
            return response()->json(
                ['error' => $this->invalidSessionMessage()],
                200
            );
        }

        $user = $this->findUser($userId);

        if (! $user) {
            $request->session()->forget('auth.two_factor.user_id');
            $request->session()->forget(self::REMEMBER_SESSION_KEY);

            return response()->json(
                ['error' => $this->invalidSessionMessage()],
                200
            );
        }

        // Deliberately NOT gated on shouldChallenge(): the trigger rules decided
        // when the challenge started, and the user is already sitting on the
        // code screen. Re-evaluating them here would strand anyone whose IP
        // changed back mid-challenge (or who is resending from staging) with a
        // screen they cannot get past. 'never', handled above, is the one case
        // where the channel itself is off.
        try {
            $manager->send($user, $manager->issue($user));
        } catch (\Throwable $e) {
            return response()->json(
                [
                    'error' => ModuleConfig::message(
                        'auth',
                        'two_factor_send_failed',
                        'The verification code could not be sent, please try again.'
                    ),
                ],
                200
            );
        }

        return response()->json(['error' => '']);
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
        // Read the remember choice before the session is torn down. It is the
        // one made at the original credentialed login, not anything in the
        // challenge request.
        $remember = $this->takeStashedRemember($user);

        // Clear the 2FA session
        session()->forget('auth.two_factor.user_id');

        // Log in the user
        Auth::login($user, $remember);

        // Handle multiple sessions if needed
        // Note: We can't logout other devices here because we don't have the password
        // in the 2FA verification request. This is handled in the initial login step.
        //
        // And no session rotation of our own: Auth::login() has already done it.
        // See csrfToken().
    }

    /**
     * The session's live CSRF token, returned with every stateful auth response.
     *
     * This is the contract, and it exists because the framework rotates the
     * session out from under the page that called us.
     *
     * Auth::login() -> updateSession() rotates the session, and WHAT it rotates
     * depends on the framework version:
     *
     *   Laravel 11 and earlier   session->migrate(true)     id only
     *   Laravel 12 and later     session->regenerate(true)  id AND CSRF token
     *
     * That change is deliberate framework security - a privilege change should
     * not leave the old CSRF token valid - and this package does not fight it,
     * on either version. It also means a single-page app that shows the 2FA
     * prompt without reloading is left holding a stale <meta csrf-token>: on
     * Laravel 12 its next POST gets 419 while its GETs carry on working, which
     * is exactly how the bug was first reported.
     *
     * So the fix is not to suppress the rotation, it is to tell the caller: every
     * successful login response - plain, requires_two_factor, and the challenge
     * completion - carries the post-handling token here, and the frontend
     * resyncs its meta tag from it. That works identically whether or not the
     * framework rotated anything, which is what makes it safe across versions.
     *
     * Nothing is disclosed: the token is already rendered into the page being
     * answered.
     *
     * (logout() is the one place that still rotates the token explicitly, via
     * regenerateToken() - there a fresh token is the entire point.)
     */
    protected function csrfToken(Request $request): ?string
    {
        return $request->hasSession() ? $request->session()->token() : null;
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
        if (! app()->environment('production')) {
            $request->validate([
                'user_id' => 'required|exists:users,id',
            ]);

            $user = $this->findUser($request->user_id);

            if (!$user) {
                return response()->json(
                    [
                        'error' => $this->userNotFoundMessage(),
                    ],
                    200
                );
            }

            // Skip 2FA validation and create token
            return response()->json(
                ['id' => $this->createApiToken($user, $request)],
                200
            );
        }

        // Normal 2FA validation for production
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'code' => 'required|string',
            'remember' => 'sometimes|boolean',
            'device_identifier' => 'sometimes|string',
        ]);

        $user = $this->findUser($request->user_id);

        if (!$user) {
            return response()->json(
                [
                    'error' => $this->userNotFoundMessage(),
                ],
                200
            );
        }

        if ($this->usesCodeDriver()) {
            $manager = $this->twoFactorManager();
            $result = $manager->verify($user, (string) $request->code);

            if ($result !== true) {
                return response()->json(
                    [
                        'error' => ModuleConfig::message(
                            'auth',
                            $result,
                            $this->invalidCodeMessage()
                        ),
                    ],
                    200
                );
            }

            $manager->consume($user);

            if ($manager->remembersDevices() && $request->input('remember', false)) {
                TwoFactorRememberToken::createToken(
                    $user,
                    $request->input('device_identifier') ?:
                        $this->getDeviceIdentifier($request)
                );
            }

            return response()->json(
                ['id' => $this->createApiToken($user, $request)],
                200
            );
        }

        if ($this->validateTotpCode($user, (string) $request->code)) {
            // If remember is true, create a remember token for this device
            if ($request->input('remember', false)) {
                $deviceIdentifier =
                    $request->input('device_identifier') ?:
                    $this->getDeviceIdentifier($request);
                TwoFactorRememberToken::createToken($user, $deviceIdentifier);
            }

            // Create a token for API access
            return response()->json(
                ['id' => $this->createApiToken($user, $request)],
                200
            );
        }

        // If we reach here, the code was invalid
        return response()->json(
            [
                'error' => $this->invalidCodeMessage(),
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

    /*
    |--------------------------------------------------------------------------
    | Extension points
    |--------------------------------------------------------------------------
    */

    /**
     * Find the account the login form is talking about.
     *
     * @return object|null
     */
    protected function resolveUser(string $identifier, Request $request)
    {
        $resolver = ModuleConfig::callable('auth.user_resolver')
            ?: app(DefaultUserResolver::class);

        return $resolver($identifier, $request);
    }

    /**
     * Look a user up by primary key on the configured model.
     *
     * The 2FA endpoints used to hardcode the package's own User model here.
     * They now honour visns-packages.auth.user_model / user_model, which for the
     * shipped default (App\Models\User) means the challenge completes against
     * the SAME class the initial login found - previously the two halves of one
     * login could be two different classes over one row, which is why
     * Auth::login() sometimes stored a user the application's own guard did not
     * recognise.
     *
     * @param  mixed  $id
     * @return object|null
     */
    protected function findUser($id)
    {
        return ModuleConfig::userQuery('auth')->find($id);
    }

    protected function gatesRunFirst(): bool
    {
        return (bool) ModuleConfig::get(
            'auth.run_gates_before_credential_check',
            false
        );
    }

    /**
     * Run the configured gates. The first one returning a Response short-circuits
     * the login and that Response goes to the client untouched - a gate owns its
     * own status code and body.
     *
     * @return \Symfony\Component\HttpFoundation\Response|null
     */
    protected function runPreLoginGates($user, Request $request)
    {
        foreach (ModuleConfig::callables('auth.pre_login_gates') as $gate) {
            $result = $gate($user, $request);

            if ($result instanceof Response) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Fire the post-login hooks (last-login stamps, IP columns, token cleanup).
     * A hook is a side effect, never a veto: by this point the user is in.
     */
    protected function runPostLoginHooks($user, Request $request): void
    {
        foreach (ModuleConfig::callables('auth.post_login_hooks') as $hook) {
            $hook($user, $request);
        }
    }

    /**
     * Session login plus the single-session enforcement the package has always
     * applied, followed by the post-login hooks.
     *
     * The order matters and is not arbitrary. Auth::login() queues the recaller
     * cookie; logoutOtherDevices() then rehashes the password, which would
     * invalidate that cookie - so it re-queues the recaller itself off the back
     * of the new hash. Doing it the other way round would leave the user holding
     * a recaller built from a password hash that no longer exists, i.e. a
     * "remember me" that silently stops working on the next visit.
     */
    protected function loginToSession($user, Request $request, bool $remember = false): void
    {
        Auth::login($user, $remember);

        if (! ModuleConfig::get('auth.allow_multiple_sessions', false)) {
            Auth::logoutOtherDevices($request->input('password'));
        }

        $this->runPostLoginHooks($user, $request);
    }

    /*
    |--------------------------------------------------------------------------
    | Remember me
    |--------------------------------------------------------------------------
    |
    | Two different features arrive in this controller under the name
    | `remember`, and keeping them apart is the whole job of this block:
    |
    |   1. Remember ME - the session guard's recaller cookie, "keep me logged
    |      in". Governed by auth.remember_enabled. Its source of truth is the
    |      value sent to authenticate(), stashed in the session; a 2FA challenge
    |      NEVER reads it off its own request body.
    |
    |   2. Remember this DEVICE - skipping the 2FA challenge next time, stored
    |      in the package's TwoFactorRememberToken table. Governed by
    |      two_factor.remember_device. That one does read the challenge request,
    |      and always has.
    |
    | Point 1's session stash is what stops a tampered challenge POST widening
    | the session: by the time the challenge is answered the user is only half
    | authenticated, so anything in that request body is attacker-controllable
    | in a way the original credentialed request was not.
    */

    /** The session key carrying the remember choice across a 2FA challenge. */
    protected const REMEMBER_SESSION_KEY = 'auth.two_factor.remember';

    protected function rememberEnabled(): bool
    {
        return (bool) ModuleConfig::get('auth.remember_enabled', false);
    }

    /**
     * Did this login ask to be remembered, and may it be?
     *
     * Returns false whenever the feature is off, so every call site can pass the
     * result straight to Auth::login() without repeating the check.
     */
    protected function wantsRemember(Request $request, $user): bool
    {
        if (! $this->rememberEnabled()) {
            return false;
        }

        return $this->grantRemember($user, $request->boolean('remember'));
    }

    /**
     * Gate a remember request on the model actually being able to store a
     * remember token.
     */
    protected function grantRemember($user, bool $requested): bool
    {
        if (! $requested || ! $this->rememberEnabled()) {
            return false;
        }

        if (! $this->supportsRememberToken($user)) {
            // Degrade rather than fail: without the column Auth::login() would
            // throw on the save, turning a missing migration into a login
            // outage. The user simply is not remembered.
            Log::warning(
                'visns-packages: remember me is enabled but the user model cannot store a remember token; logging in without it',
                [
                    'model' => get_class($user),
                    'user_id' => $user->getKey(),
                ]
            );

            return false;
        }

        return true;
    }

    /**
     * Can this model hold a remember token?
     *
     * A model retrieved with `select *` carries every table column in its
     * attribute bag, so array_key_exists() answers this without the
     * introspection query Schema::hasColumn() would cost on every login.
     */
    protected function supportsRememberToken($user): bool
    {
        if (
            ! is_object($user) ||
            ! method_exists($user, 'getRememberToken') ||
            ! method_exists($user, 'setRememberToken') ||
            ! method_exists($user, 'getRememberTokenName')
        ) {
            return false;
        }

        $column = (string) $user->getRememberTokenName();

        // An Authenticatable can opt out entirely by returning an empty name.
        if ($column === '') {
            return false;
        }

        return method_exists($user, 'getAttributes')
            && array_key_exists($column, $user->getAttributes());
    }

    /**
     * Park the remember choice for the 2FA challenge that is about to happen.
     */
    protected function stashRemember(Request $request, $user): void
    {
        $request->session()->put(
            self::REMEMBER_SESSION_KEY,
            $this->wantsRemember($request, $user)
        );
    }

    /**
     * Read back - and clear - the remember choice made at the original login.
     *
     * Deliberately takes nothing from the current request: see the block
     * comment above.
     */
    protected function takeStashedRemember($user): bool
    {
        $stashed = (bool) session()->pull(self::REMEMBER_SESSION_KEY, false);

        return $this->grantRemember($user, $stashed);
    }

    /**
     * Log in for the API and mint the Sanctum token, running the post-login
     * hooks once the token exists so a hook can see or prune it.
     */
    protected function issueApiToken($user, Request $request): string
    {
        Auth::login($user);

        return $this->createApiToken($user, $request);
    }

    /**
     * Mint a token WITHOUT touching the session guard.
     *
     * The 2FA API endpoints have never called Auth::login() - they are stateless
     * and answer a challenge that a previous request started - so they must not
     * start doing it now.
     */
    protected function createApiToken($user, Request $request): string
    {
        $token = $user->createToken('authToken');

        $this->runPostLoginHooks($user, $request);

        return $token->plainTextToken;
    }

    /**
     * The `previous` value echoed back to the front end. '/' and '/login' are
     * blanked so a post-login redirect cannot bounce the user back to the login
     * screen; an application that routes its own redirects turns this off.
     *
     * @param  mixed  $location
     * @return mixed
     */
    protected function filteredPrevious($location)
    {
        if (! ModuleConfig::get('auth.filter_previous', true)) {
            return $location;
        }

        return $location == '/' || $location == '/login' ? '' : $location;
    }

    protected function usesCodeDriver(): bool
    {
        return $this->twoFactorManager()->driver() === 'code';
    }

    protected function twoFactorManager(): TwoFactorCodeManager
    {
        return app(TwoFactorCodeManager::class);
    }

    /**
     * Validate a TOTP/recovery code through UserController.
     *
     * UserController::validateTwoFactorCode() type-hints the package's own User
     * model, while the rest of this controller now resolves the CONFIGURED model
     * (which need not extend it). Re-reading the same row through the package
     * model keeps that call legal and touches the same database record, so
     * recovery-code consumption still persists.
     */
    protected function validateTotpCode($user, string $code): bool
    {
        $packageUser = $user instanceof \Visnsstudio\VisnsPackages\Models\User
            ? $user
            : \Visnsstudio\VisnsPackages\Models\User::find($user->getKey());

        if (! $packageUser) {
            return false;
        }

        return (bool) (new UserController())->validateTwoFactorCode(
            $packageUser,
            $code
        );
    }

    protected function invalidSessionMessage(): string
    {
        return ModuleConfig::message(
            'auth',
            'invalid_two_factor_session',
            'Invalid two-factor authentication session.'
        );
    }

    protected function userNotFoundMessage(): string
    {
        return ModuleConfig::message('auth', 'user_not_found', 'User not found.');
    }

    protected function invalidCodeMessage(): string
    {
        return ModuleConfig::message(
            'auth',
            'invalid_two_factor_code',
            'The provided two-factor authentication code was invalid.'
        );
    }
}
