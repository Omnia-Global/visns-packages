<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;
use Laragear\WebAuthn\Models\WebAuthnCredential;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Traits\IssuesAuthSession;

/**
 * Passkeys (WebAuthn).
 *
 * Two ceremonies, either side of the sign-in line:
 *
 *   enrolment (signed in)  options -> browser creates a key pair -> store
 *   sign-in   (guest)      options -> browser signs the challenge -> verify
 *
 * The cryptography is laragear/webauthn's; what lives here is the policy
 * around it - who may enrol, what a credential is called, and what the front
 * end gets back.
 *
 * Challenges are 16 random bytes held in the session and *pulled* when a
 * ceremony is verified (Laragear\WebAuthn\Challenge\SessionChallengeRepository),
 * so each is good for one attempt and for the configured timeout. Nothing here
 * needs to enforce that separately.
 *
 * A successful sign-in answers in exactly the shape AuthController's
 * authenticate() does, using the same post-login hooks, previous-URL filter
 * and CSRF token read - hence the shared trait rather than a second copy of
 * any of them. The front end branches on that envelope, so it is contract.
 */
class PasskeyController extends \App\Http\Controllers\Controller
{
    use IssuesAuthSession;

    /**
     * Whether this install offers passkeys at all.
     *
     * Read by the route registration in the service provider, by every action
     * below - a disabled install must not start a ceremony it would refuse to
     * finish - and by consuming applications, which render the switch into the
     * page so the login screen knows whether to draw the button.
     */
    public static function isEnabled(): bool
    {
        return (bool) ModuleConfig::get('passkeys.enabled', false);
    }

    /* ---------------------------------------------------------------------
     | Enrolment - signed in
     * ------------------------------------------------------------------ */

    /**
     * The credential-creation options for the browser.
     */
    public function registerOptions(AttestationRequest $request): Responsable
    {
        $this->abortIfDisabled();

        // `userless()` asks for a resident (discoverable) key, which is what
        // makes this a *passkey* rather than a second factor: the credential
        // carries the user handle, so signing in needs no username first.
        //
        // User verification is left at the WebAuthn default of "preferred" -
        // neither fastRegistration() (discouraged) nor secureRegistration()
        // (required). Required would turn away authenticators with no PIN or
        // biometric, and this is the only factor being presented.
        return $request->userless()->toCreate();
    }

    /**
     * Store the credential the browser just created.
     */
    public function register(AttestedRequest $request): JsonResponse
    {
        $this->abortIfDisabled();

        $validated = $request->validate([
            'alias' =>
                'nullable|string|max:' .
                (int) ModuleConfig::get('passkeys.alias_max_length', 60),
        ]);

        $alias = trim((string) ($validated['alias'] ?? ''));

        $request->save([
            // Blank is allowed; the management screen falls back to the date.
            'alias' => $alias !== '' ? $alias : null,
        ]);

        return response()->json([
            'error' => '',
            'passkey' => $this->present(
                $request
                    ->user()
                    ->webAuthnCredentials()
                    ->latest()
                    ->first()
            ),
        ]);
    }

    /**
     * The signed-in user's passkeys.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'error' => '',
            'enabled' => static::isEnabled(),
            'passkeys' => $request
                ->user()
                ->webAuthnCredentials()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn(WebAuthnCredential $credential) => $this->present(
                    $credential
                ))
                ->all(),
        ]);
    }

    /**
     * Remove one of the signed-in user's passkeys.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        // Scoped to the relation, so one user cannot name another's credential
        // - and the 404 below says the same thing whether the credential does
        // not exist or belongs to somebody else.
        $credential = $request
            ->user()
            ->webAuthnCredentials()
            ->whereKey($id)
            ->first();

        if (!$credential) {
            throw new NotFoundHttpException(
                ModuleConfig::get(
                    'passkeys.messages.not_found',
                    'That passkey no longer exists.'
                )
            );
        }

        $credential->delete();

        return response()->json(['error' => '']);
    }

    /* ---------------------------------------------------------------------
     | Sign-in - guest
     * ------------------------------------------------------------------ */

    /**
     * The assertion challenge.
     *
     * Deliberately given no user to narrow by: with no `allowCredentials`
     * list the browser offers whichever discoverable passkey it holds for
     * this domain, so sign-in needs no email typed first - and the response
     * says nothing about which accounts exist.
     */
    public function loginOptions(AssertionRequest $request): Responsable
    {
        $this->abortIfDisabled();

        return $request->toVerify();
    }

    /**
     * Verify the assertion and open a session.
     */
    public function login(AssertedRequest $request): JsonResponse
    {
        $this->abortIfDisabled();

        // The signature check happens inside the auth provider, which is why
        // config/auth.php's users provider has to be the `eloquent-webauthn`
        // driver for any of this to work. `login()` regenerates the session on
        // success, which is why the CSRF token is read out of it afterwards.
        //
        // This path deliberately bypasses the two-factor challenge that
        // authenticate() applies to an email and password. A passkey is
        // already multi-factor - something you have (the device holding the
        // private key) plus something you are or know (the biometric or PIN
        // that unlocks it) - so a second factor on top would ask for the same
        // assurance twice.
        $user = $request->login();

        if (!$user) {
            return response()->json(
                [
                    'error' => ModuleConfig::get(
                        'passkeys.messages.assertion_rejected',
                        'That passkey was not accepted. Please try again, or sign in with your email.'
                    ),
                    'previous' => $this->filteredPrevious(
                        $request->input('location')
                    ),
                    'user' => '',
                    'requires_two_factor' => false,
                    'csrf_token' => $this->csrfToken($request),
                ],
                422
            );
        }

        // The hooks authenticate() runs on every other successful sign-in -
        // last-logged IP, last-logged timestamp, whatever an application has
        // added. Not loginToSession(): Auth::login() and the session rotation
        // have already happened inside the ceremony above, and the
        // single-session enforcement in that helper rehashes the password,
        // which a passkey sign-in does not have.
        $this->runPostLoginHooks($user, $request);

        // Same envelope as authenticate(), so the login screen handles both
        // sign-in paths with one branch. The csrf_token matters: the front end
        // syncs it after a sign-in, and the session regeneration above has
        // already invalidated the one on the page.
        return response()->json([
            'error' => '',
            'previous' => $this->filteredPrevious($request->input('location')),
            'user' => $user->load(
                (array) ModuleConfig::get('passkeys.user_relations', [
                    'roles.permissions',
                ])
            ),
            'requires_two_factor' => false,
            'csrf_token' => $this->csrfToken($request),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Everything the management screen shows about one credential.
     *
     * Never the public key or the counter: the first is the credential's
     * substance and the second says nothing a person can act on.
     */
    protected function present(WebAuthnCredential $credential): array
    {
        return [
            'id' => $credential->getKey(),
            'alias' => $credential->alias,
            'origin' => $credential->origin,
            'transports' => $credential->transports,
            'disabled' => $credential->isDisabled(),
            'created_at' => optional($credential->created_at)->toIso8601String(),
            'last_used_at' => optional(
                $credential->last_used_at
            )->toIso8601String(),
        ];
    }

    /**
     * Refuse to start a ceremony this install would not finish.
     */
    protected function abortIfDisabled(): void
    {
        abort_unless(
            static::isEnabled(),
            404,
            ModuleConfig::get(
                'passkeys.messages.disabled',
                'Passkeys are not enabled on this site.'
            )
        );
    }
}
