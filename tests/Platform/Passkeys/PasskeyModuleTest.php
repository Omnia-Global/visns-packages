<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Passkeys;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laragear\WebAuthn\Models\WebAuthnCredential;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The passkeys (WebAuthn) module.
 *
 * What is testable without an authenticator is the contract around the two
 * ceremonies: that the module ships off, who may start one, that the
 * challenges are well formed and single-use, that a forged assertion is
 * refused, and that the management endpoints are scoped to their owner.
 *
 * What is NOT covered is a complete round trip - creating a real key pair,
 * signing a challenge with it and having the signature verified.
 * laragear/webauthn ships no authenticator test double, and hand-rolling one
 * would be testing a reimplementation of the library rather than this module.
 */
class PasskeyModuleTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return array_merge(
            [\Laragear\WebAuthn\WebAuthnServiceProvider::class],
            parent::getPackageProviders($app)
        );
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        // Routes and the CredentialAsserted listener are wired at boot, so the
        // module has to be on before the provider runs.
        $app['config']->set('visns-packages.passkeys.enabled', true);

        // The half of the wiring the package cannot do for an application: the
        // provider driver that knows how to verify an assertion.
        $app['config']->set('auth.providers.users.driver', 'eloquent-webauthn');
        $app['config']->set('auth.providers.users.password_fallback', true);
    }

    protected function defineDatabaseMigrations()
    {
        parent::defineDatabaseMigrations();

        // The shipped migration, not a restatement of it.
        $this->runPackageMigration(
            '2026_08_29_120000_create_webauthn_credentials_table.php'
        );
    }

    /* ---------------------------------------------------------------------
     | The module ships off
     * ------------------------------------------------------------------ */

    public function test_the_module_is_disabled_by_default(): void
    {
        // Read straight off the shipped config, before this test class turns
        // it on: an existing consumer must see no new endpoints on upgrade.
        $shipped = require __DIR__ . '/../../../config/visns-packages.php';

        $this->assertFalse($shipped['passkeys']['enabled']);
    }

    public function test_the_default_sign_in_uris_are_the_ones_the_front_end_posts_to(): void
    {
        $shipped = require __DIR__ . '/../../../config/visns-packages.php';

        // visns-components' Login screen has these two baked in; changing
        // them here would break every consuming front end silently.
        $this->assertSame(
            'login/passkey/options',
            $shipped['passkeys']['uris']['login_options']
        );
        $this->assertSame(
            'login/passkey',
            $shipped['passkeys']['uris']['login']
        );
    }

    public function test_the_relying_party_alias_is_registered(): void
    {
        $this->assertSame(
            \Visnsstudio\VisnsPackages\Middleware\ResolveWebAuthnRelyingParty::class,
            $this->app['router']->getMiddleware()['webauthn.rp']
        );
    }

    /* ---------------------------------------------------------------------
     | Enrolment is a signed-in act
     * ------------------------------------------------------------------ */

    public function test_a_guest_cannot_reach_any_of_the_enrolment_endpoints(): void
    {
        // A passkey is added to an account that has already proved itself. If
        // a guest could enrol one, the passkey would *be* the account.
        $this->postJson('/ajax/passkeys/options')->assertUnauthorized();
        $this->postJson('/ajax/passkeys/register')->assertUnauthorized();
        $this->getJson('/ajax/passkeys')->assertUnauthorized();
        $this->deleteJson('/ajax/passkeys/whatever')->assertUnauthorized();
    }

    public function test_the_enrolment_options_are_a_well_formed_discoverable_credential_request(): void
    {
        $user = $this->makeUser('jane@example.test');

        $response = $this->actingAs($user)->postJson('/ajax/passkeys/options');

        $response->assertOk();

        $options = $response->json();

        // 16 random bytes, base64url - 22 characters, no padding.
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9_-]{22}$/',
            $options['challenge'] ?? ''
        );

        // A resident key is what makes this a passkey rather than a second
        // factor: without it, signing in would need a username first.
        $this->assertSame(
            'required',
            $options['authenticatorSelection']['residentKey'] ?? null
        );

        // The relying party is the host being browsed, not APP_URL's - the
        // work of the `webauthn.rp` middleware.
        $this->assertSame('localhost', $options['rp']['id'] ?? null);

        // The user handle is a UUID, not the account's email or id.
        $this->assertSame('jane@example.test', $options['user']['name'] ?? null);
        $this->assertNotSame((string) $user->id, $options['user']['id'] ?? null);
    }

    public function test_the_enrolment_challenge_is_kept_in_the_session(): void
    {
        $this->actingAs($this->makeUser('jane@example.test'))
            ->postJson('/ajax/passkeys/options')
            ->assertOk()
            ->assertSessionHas('_webauthn');
    }

    public function test_a_garbage_attestation_is_refused_and_stores_nothing(): void
    {
        $user = $this->makeUser('jane@example.test');

        $this->actingAs($user)->postJson('/ajax/passkeys/options')->assertOk();

        $this->actingAs($user)
            ->postJson('/ajax/passkeys/register', [
                'id' => 'made-up',
                'rawId' => 'made-up',
                'response' => [
                    'clientDataJSON' => 'bm90LWNsaWVudC1kYXRh',
                    'attestationObject' => 'bm90LWFuLWF0dGVzdGF0aW9u',
                ],
                'type' => 'public-key',
                'alias' => 'My laptop',
            ])
            ->assertStatus(422);

        $this->assertSame(0, WebAuthnCredential::count());
    }

    /* ---------------------------------------------------------------------
     | Managing what is enrolled
     * ------------------------------------------------------------------ */

    public function test_the_list_shows_a_users_own_passkeys_and_nobody_elses(): void
    {
        $jane = $this->makeUser('jane@example.test');
        $john = $this->makeUser('john@example.test');

        $this->makeCredential($jane, 'jane-key', "Jane's laptop");
        $this->makeCredential($john, 'john-key', "John's phone");

        $response = $this->actingAs($jane)->getJson('/ajax/passkeys');

        $response->assertOk();
        $response->assertJsonCount(1, 'passkeys');
        $response->assertJsonPath('passkeys.0.alias', "Jane's laptop");

        // Nothing that would let a leaked response impersonate the key.
        $this->assertArrayNotHasKey('public_key', $response->json('passkeys.0'));
    }

    public function test_a_user_can_delete_their_own_passkey(): void
    {
        $jane = $this->makeUser('jane@example.test');
        $this->makeCredential($jane, 'jane-key', "Jane's laptop");

        $this->actingAs($jane)
            ->deleteJson('/ajax/passkeys/jane-key')
            ->assertOk();

        $this->assertSame(0, WebAuthnCredential::count());
    }

    public function test_a_user_cannot_delete_someone_elses_passkey(): void
    {
        $jane = $this->makeUser('jane@example.test');
        $john = $this->makeUser('john@example.test');
        $this->makeCredential($john, 'john-key', "John's phone");

        $this->actingAs($jane)
            ->deleteJson('/ajax/passkeys/john-key')
            ->assertNotFound();

        $this->assertSame(1, WebAuthnCredential::count());
    }

    /* ---------------------------------------------------------------------
     | Signing in
     * ------------------------------------------------------------------ */

    public function test_the_login_options_are_username_less_and_reveal_no_accounts(): void
    {
        $jane = $this->makeUser('jane@example.test');
        $this->makeCredential($jane, 'jane-key', "Jane's laptop");

        $response = $this->postJson('/login/passkey/options');

        $response->assertOk()->assertSessionHas('_webauthn');

        // No allowCredentials: the browser offers whatever discoverable
        // passkey it holds, and the response says nothing about who has one.
        $this->assertArrayNotHasKey('allowCredentials', $response->json());
        $this->assertStringNotContainsString(
            'jane-key',
            $response->getContent()
        );
    }

    public function test_a_forged_assertion_does_not_sign_anybody_in(): void
    {
        $jane = $this->makeUser('jane@example.test');
        $this->makeCredential($jane, 'jane-key', "Jane's laptop");

        $this->postJson('/login/passkey/options')->assertOk();

        // The credential ID is real - everything signed with it is not.
        $response = $this->postJson('/login/passkey', [
            'id' => 'jane-key',
            'rawId' => 'jane-key',
            'response' => [
                'authenticatorData' => 'bm90LWF1dGhlbnRpY2F0b3ItZGF0YQ',
                'clientDataJSON' => 'bm90LWNsaWVudC1kYXRh',
                'signature' => 'bm90LWEtc2lnbmF0dXJl',
                'userHandle' => null,
            ],
            'type' => 'public-key',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_a_challenge_is_spent_by_the_first_attempt_at_it(): void
    {
        $jane = $this->makeUser('jane@example.test');
        $this->makeCredential($jane, 'jane-key', "Jane's laptop");

        $challenge = $this->postJson('/login/passkey/options')
            ->assertOk()
            ->json('challenge');

        $assertion = $this->assertionFor('jane-key', $challenge);

        // Answers the challenge correctly and is refused anyway - the
        // signature is nonsense. What matters here is the side effect.
        $this->postJson('/login/passkey', $assertion)->assertStatus(422);

        // Pulled, not read: the attempt consumed it. So replaying the very
        // same blob has nothing left to check against, which is what stops a
        // captured assertion being reused.
        $this->assertNull(session('_webauthn'));
        $this->postJson('/login/passkey', $assertion)->assertStatus(422);
        $this->assertGuest();
    }

    /* ---------------------------------------------------------------------
     | Who a verified assertion is allowed to sign in
     * ------------------------------------------------------------------ */

    public function test_a_disabled_account_may_not_be_signed_in_by_a_passkey(): void
    {
        // The predicate on its own, because there is no way from here to get
        // a real signature past the auth provider - see the note at the top of
        // this class. It is the callback login() hands to laragear, so what is
        // asserted here is exactly what decides a live sign-in.
        //
        // The password path refuses a disabled account; a passkey enrolled
        // before the account was switched off must not be a way round that.
        $disabled = $this->makeUser('gone@example.test');
        $disabled->disabled = true;
        $disabled->save();

        $this->assertFalse(
            ExposedPasskeyController::maySignIn($disabled->fresh())
        );

        $this->assertTrue(
            ExposedPasskeyController::maySignIn(
                $this->makeUser('jane@example.test')->fresh()
            )
        );
    }

    /* ---------------------------------------------------------------------
     | last_used_at
     * ------------------------------------------------------------------ */

    public function test_last_used_at_comes_back_as_a_date_not_a_raw_string(): void
    {
        $jane = $this->makeUser('jane@example.test');
        $credential = $this->makeCredential($jane, 'jane-key', "Jane's laptop");

        $credential
            ->newQuery()
            ->whereKey($credential->getKey())
            ->update(['last_used_at' => '2026-08-30 04:05:06']);

        // The column is this package's, added by its own migration; laragear's
        // model does not know about it, so without the cast the provider
        // registers, present()'s `optional(...)->toIso8601String()` is called
        // on a plain string and answers null on every credential ever used.
        $this->actingAs($jane)
            ->getJson('/ajax/passkeys')
            ->assertOk()
            ->assertJsonPath(
                'passkeys.0.last_used_at',
                \Illuminate\Support\Carbon::parse('2026-08-30 04:05:06')
                    ->toIso8601String()
            );
    }

    public function test_a_credential_that_has_never_been_used_says_so_rather_than_guessing(): void
    {
        $jane = $this->makeUser('jane@example.test');
        $this->makeCredential($jane, 'jane-key', "Jane's laptop");

        $this->actingAs($jane)
            ->getJson('/ajax/passkeys')
            ->assertOk()
            ->assertJsonPath('passkeys.0.last_used_at', null);
    }

    /* ---------------------------------------------------------------------
     | The kill switch
     * ------------------------------------------------------------------ */

    public function test_disabling_passkeys_closes_the_ceremonies_without_touching_what_is_enrolled(): void
    {
        $jane = $this->makeUser('jane@example.test');
        $this->makeCredential($jane, 'jane-key', "Jane's laptop");

        // The routes stay registered - they were declared at boot - but every
        // ceremony refuses to start.
        config()->set('visns-packages.passkeys.enabled', false);

        $this->postJson('/login/passkey/options')->assertNotFound();
        $this->actingAs($jane)
            ->postJson('/ajax/passkeys/options')
            ->assertNotFound();

        // Enrolled credentials stay where they are - the switch stops them
        // being used, it does not throw them away.
        $this->assertSame(1, WebAuthnCredential::count());
        $this->actingAs($jane)
            ->getJson('/ajax/passkeys')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonCount(1, 'passkeys');
    }

    /* ---------------------------------------------------------------------
     | Passwords still work
     * ------------------------------------------------------------------ */

    public function test_the_webauthn_provider_driver_leaves_password_sign_in_alone(): void
    {
        // Swapping the auth provider driver is the one change this module asks
        // of an application that touches every existing sign-in.
        $this->makeUser('jane@example.test', 'correct-horse');

        $this->postJson('/login/authenticate', [
            'email' => 'jane@example.test',
            'password' => 'correct-horse',
        ])->assertOk();

        $this->assertAuthenticated();
    }

    public function test_a_wrong_password_is_still_a_wrong_password(): void
    {
        $this->makeUser('jane@example.test', 'correct-horse');

        $this->postJson('/login/authenticate', [
            'email' => 'jane@example.test',
            'password' => 'battery-staple',
        ]);

        $this->assertGuest();
    }

    /* ------------------------------------------------------------------ */

    private function makeUser(string $email, string $password = 'secret'): User
    {
        return User::create([
            'name' => 'Test Person',
            'email' => $email,
            'password' => Hash::make($password),
        ]);
    }

    /**
     * An enrolled credential, as the attestation ceremony would have left it.
     *
     * The public key is nonsense on purpose: every test that uses one of these
     * is about ownership or listing, never about verifying a signature.
     */
    private function makeCredential(
        User $user,
        string $id,
        string $alias
    ): WebAuthnCredential {
        $credential = $user->makeWebAuthnCredential([
            'id' => $id,
            'user_id' =>
                '00000000-0000-0000-0000-00000000000' . ($user->id % 10),
            'alias' => $alias,
            'counter' => 0,
            'rp_id' => 'localhost',
            'origin' => 'http://localhost',
            'transports' => ['internal'],
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'public_key' => 'not-a-real-key',
            'attestation_format' => 'none',
        ]);

        $credential->save();

        return $credential;
    }

    /**
     * An assertion shaped exactly as a browser would send one - right up to
     * the signature, which is nonsense.
     *
     * Enough structure to carry the pipeline past the challenge check, which
     * is the part this test is about; it then fails on the relying party hash
     * and the signature, as anything unsigned should.
     */
    private function assertionFor(
        string $credentialId,
        string $challenge
    ): array {
        // 32-byte relying party hash + flags (user present, user verified) +
        // a four-byte counter. Zeroes for the hash: it is wrong on purpose.
        $authenticatorData = str_repeat("\0", 32) . chr(0x05) . pack('N', 1);

        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'http://localhost',
        ]);

        return [
            'id' => $credentialId,
            'rawId' => $credentialId,
            'response' => [
                'authenticatorData' => $this->base64Url($authenticatorData),
                'clientDataJSON' => $this->base64Url($clientDataJson),
                'signature' => $this->base64Url('not-a-signature'),
                'userHandle' => null,
            ],
            'type' => 'public-key',
        ];
    }

    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}

/**
 * The sign-in policy predicate, reachable from a test.
 *
 * `candidateMaySignIn()` is protected because it is a hook for a consuming
 * application to widen, not part of the controller's public surface - and a
 * subclass is how an application would reach it, so it is also how a test
 * should. Reflection would assert the same thing while proving nothing about
 * whether the method is actually overridable.
 */
class ExposedPasskeyController extends
    \Visnsstudio\VisnsPackages\Controllers\PasskeyController
{
    public static function maySignIn($candidate): bool
    {
        return static::candidateMaySignIn($candidate);
    }
}
