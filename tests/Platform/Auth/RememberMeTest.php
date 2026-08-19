<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Contracts\TwoFactorCodeSender;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\CollectingCodeSender;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\NoRememberColumnUser;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * "Remember me" — the session guard's recaller cookie.
 *
 * The login screens have always sent `remember` and these endpoints have always
 * accepted it, and until now nothing reached the guard: no recaller was ever
 * issued and the tick box did nothing. These tests assert on the cookie itself
 * rather than on any internal flag, because the cookie IS the feature.
 */
class RememberMeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CollectingCodeSender::reset();
    }

    private function recallerName(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    /**
     * A cookie's plaintext value.
     *
     * EncryptCookies wraps everything on the way out - including the empty
     * forget-cookie logout queues - so the raw response value is ciphertext
     * carrying Laravel's name-bound prefix. Decrypt when we can and fall back to
     * the raw value, so these assertions read the same whether or not cookie
     * encryption is in the stack.
     */
    private function decryptCookie(string $value): string
    {
        try {
            return \Illuminate\Cookie\CookieValuePrefix::remove(
                decrypt($value, false)
            );
        } catch (\Throwable $e) {
            return $value;
        }
    }

    /** Whatever is on the response under the recaller's name, live or forget. */
    private function recallerCookie($response)
    {
        return $response->getCookie($this->recallerName(), false);
    }

    /**
     * The recaller cookie on a response, or null when none was issued.
     *
     * A queued *forget* cookie (empty payload, expiry in the past) is the
     * absence of a recaller, not the presence of one.
     */
    private function recaller($response)
    {
        $cookie = $this->recallerCookie($response);

        if ($cookie === null) {
            return null;
        }

        return $this->decryptCookie($cookie->getValue()) === '' ? null : $cookie;
    }

    /** The recaller's "{id}|{token}|{passwordHash}" payload. */
    private function recallerPayload($response): ?string
    {
        $cookie = $this->recaller($response);

        return $cookie === null
            ? null
            : $this->decryptCookie($cookie->getValue());
    }

    /**
     * Drop cookies queued by an earlier request in the same test.
     *
     * The application - and therefore its CookieJar - lives for the whole test,
     * while in production every request gets a fresh one. Without this a
     * recaller queued by a previous login is still queued, and would be
     * re-attached to a later response that never asked for it.
     */
    private function flushQueuedCookies(): void
    {
        $this->app['cookie']->flushQueuedCookies();
    }

    private function user(array $attributes = []): User
    {
        return User::create(array_merge([
            'firstname' => 'Jo',
            'email' => 'jo@example.test',
            'mobile' => '0412345678',
            'password' => Hash::make('correct-horse'),
        ], $attributes));
    }

    private function login(array $extra = [])
    {
        return $this->postJson('/login/authenticate', array_merge([
            'email' => 'jo@example.test',
            'password' => 'correct-horse',
        ], $extra));
    }

    /*
    |--------------------------------------------------------------------------
    | The feature is off by default
    |--------------------------------------------------------------------------
    */

    public function test_it_ships_disabled(): void
    {
        $shipped = require __DIR__ . '/../../../config/visns-packages.php';

        $this->assertFalse($shipped['auth']['remember_enabled']);
    }

    public function test_remember_is_ignored_while_the_feature_is_off(): void
    {
        $this->user();

        // Today's behaviour, preserved: the field is accepted and dropped.
        $response = $this->login(['remember' => true])->assertOk();

        $this->assertNull($this->recaller($response));
        $this->assertAuthenticated();
    }

    /*
    |--------------------------------------------------------------------------
    | The plain (no 2FA) path
    |--------------------------------------------------------------------------
    */

    public function test_remember_true_issues_a_recaller_cookie(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);

        $user = $this->user();

        $response = $this->login(['remember' => true])->assertOk();

        $this->assertNotNull($this->recaller($response), 'no recaller cookie was issued');

        // The recaller is "{id}|{remember_token}|{password_hash}".
        [$id, $token] = explode('|', $this->recallerPayload($response));

        $this->assertSame((string) $user->id, $id);
        $this->assertNotEmpty($user->fresh()->remember_token);
        $this->assertSame($user->fresh()->remember_token, $token);
    }

    public function test_the_recaller_outlives_the_session_cookie(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);

        $this->user();

        $cookie = $this->recaller($this->login(['remember' => true]));

        // Whatever the configured lifetime, the point of a recaller is that it
        // is not a session cookie.
        $this->assertGreaterThan(time() + 86400, $cookie->getExpiresTime());
    }

    public function test_remember_false_issues_no_recaller(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);

        $this->user();

        $this->assertNull(
            $this->recaller($this->login(['remember' => false]))
        );
    }

    public function test_an_absent_remember_field_issues_no_recaller(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);

        $this->user();

        $this->assertNull($this->recaller($this->login()));
    }

    public function test_a_failed_login_issues_no_recaller(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);

        $this->user();

        $response = $this->login([
            'password' => 'wrong',
            'remember' => true,
        ]);

        $this->assertNull($this->recaller($response));
        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | Interplay with logoutOtherDevices
    |--------------------------------------------------------------------------
    */

    public function test_the_recaller_survives_the_single_session_password_rehash(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);
        // The default: single-session enforcement is ON, so every login rehashes
        // the password via Auth::logoutOtherDevices().
        config()->set('visns-packages.auth.allow_multiple_sessions', false);

        $user = $this->user();

        $response = $this->login(['remember' => true]);

        $this->assertNotNull($this->recaller($response));

        // The recaller embeds a slice of the password hash. logoutOtherDevices()
        // rewrites that hash, so a recaller issued before it and not re-queued
        // after would be dead on the user's next visit - a "remember me" that
        // silently forgets.
        [, , $passwordHashSlice] = explode('|', $this->recallerPayload($response));

        $this->assertNotEmpty($passwordHashSlice);
        $this->assertStringStartsWith(
            $passwordHashSlice,
            $user->fresh()->password
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Carrying the choice across a 2FA challenge (code driver)
    |--------------------------------------------------------------------------
    */

    private function useCodeDriver(): void
    {
        config()->set('visns-packages.auth.two_factor.driver', 'code');
        config()->set(
            'visns-packages.auth.two_factor.sender',
            CollectingCodeSender::class
        );
        $this->app->bind(TwoFactorCodeSender::class, CollectingCodeSender::class);

        $this->app->detectEnvironment(fn() => 'production');
        $this->withoutMiddleware(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class
        );
    }

    public function test_the_challenge_response_carries_the_recaller(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);
        $this->useCodeDriver();

        $user = $this->user();

        // The challenge itself must not log anyone in, so no recaller yet.
        $start = $this->login(['remember' => true])
            ->assertJsonPath('requires_two_factor', true);

        $this->assertNull($this->recaller($start));
        $this->assertGuest();

        $response = $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
        ])->assertJsonPath('error', '');

        $this->assertNotNull(
            $this->recaller($response),
            'the challenge did not issue a recaller'
        );
        $this->assertSame(
            (string) $user->id,
            explode('|', $this->recallerPayload($response))[0]
        );
        $this->assertAuthenticated();
    }

    public function test_a_challenge_without_remember_at_login_gets_no_recaller(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);
        $this->useCodeDriver();

        $this->user();

        $this->login(['remember' => false]);

        $this->assertNull($this->recaller(
            $this->postJson('/login/two-factor-challenge', [
                'code' => CollectingCodeSender::lastCode(),
            ])
        ));
    }

    public function test_the_challenge_post_cannot_widen_the_session(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);
        $this->useCodeDriver();

        $this->user();

        // Logged in WITHOUT asking to be remembered...
        $this->login(['remember' => false]);

        // ...then the half-authenticated challenge request asks for it. The
        // session's lifetime must be decided by the request that proved the
        // password, not by one an attacker can shape after intercepting a code.
        $response = $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
            'remember' => true,
        ])->assertJsonPath('error', '');

        $this->assertNull(
            $this->recaller($response),
            'the challenge POST widened the session'
        );
        $this->assertAuthenticated();
    }

    public function test_the_challenge_post_cannot_narrow_it_either(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);
        $this->useCodeDriver();

        $this->user();

        $this->login(['remember' => true]);

        // The session is the single source of truth in both directions.
        $response = $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
            'remember' => false,
        ]);

        $this->assertNotNull($this->recaller($response));
    }

    public function test_the_stashed_choice_does_not_leak_into_a_later_login(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);
        $this->useCodeDriver();

        $this->user();

        $this->login(['remember' => true]);
        $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
        ]);

        // A second challenge, this time without asking to be remembered: the
        // first login's parked choice must already be spent.
        $this->flushSession();
        $this->flushQueuedCookies();
        $this->login(['remember' => false]);

        $this->assertNull($this->recaller(
            $this->postJson('/login/two-factor-challenge', [
                'code' => CollectingCodeSender::lastCode(),
            ])
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Carrying the choice across a 2FA challenge (TOTP driver)
    |--------------------------------------------------------------------------
    */

    public function test_the_totp_challenge_carries_the_recaller(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);
        config()->set('visns-packages.auth.two_factor.driver', 'totp');

        // Outside production the TOTP endpoint completes the login without
        // validating a code, which is exactly the path to assert the recaller on
        // without standing up an authenticator.
        $user = $this->user([
            'two_factor_secret' => encrypt('SECRETSECRETSECR'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->app->detectEnvironment(fn() => 'production');
        $this->withoutMiddleware(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class
        );

        $this->login(['remember' => true])
            ->assertJsonPath('requires_two_factor', true);

        // Back to a non-production environment for the challenge itself, where
        // the endpoint completes the login without a code.
        $this->app->detectEnvironment(fn() => 'testing');

        $response = $this->postJson('/login/two-factor-challenge', [])
            ->assertJsonPath('user.id', $user->id);

        $this->assertNotNull(
            $this->recaller($response),
            'the TOTP challenge did not issue a recaller'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    */

    public function test_logout_clears_the_recaller_and_the_stored_token(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);

        $user = $this->user();

        $issued = $this->recaller($this->login(['remember' => true]));

        $issuedToken = $user->fresh()->remember_token;
        $this->assertNotEmpty($issuedToken);

        // Hand the recaller back on the way out. Laravel only queues the
        // forget-cookie when the request actually carries one, and the test
        // client - unlike a browser - does not replay response cookies.
        $this->flushQueuedCookies();

        $response = $this->withCookie($this->recallerName(), $issued->getValue())
            ->get('/logout')
            ->assertRedirect('/login');

        // Laravel queues a forget-cookie for the recaller...
        $forget = $this->recallerCookie($response);

        $this->assertNotNull($forget);
        $this->assertSame('', $this->decryptCookie($forget->getValue()));
        $this->assertLessThan(time(), $forget->getExpiresTime());
        $this->assertNull($this->recaller($response));

        // ...and cycles the stored token, so a copy of the old cookie taken off
        // the wire is worthless too.
        $this->assertNotSame($issuedToken, $user->fresh()->remember_token);
        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | Degrading when the model cannot store a token
    |--------------------------------------------------------------------------
    */

    public function test_a_model_without_the_column_logs_in_without_being_remembered(): void
    {
        config()->set('visns-packages.auth.remember_enabled', true);
        config()->set('visns-packages.user_model', NoRememberColumnUser::class);
        config()->set('auth.providers.users.model', NoRememberColumnUser::class);

        NoRememberColumnUser::create([
            'firstname' => 'Jo',
            'email' => 'jo@example.test',
            'password' => Hash::make('correct-horse'),
        ]);

        Log::spy();

        // A missing migration must cost the tick box, not the login.
        $response = $this->login(['remember' => true])
            ->assertOk()
            ->assertJsonPath('error', '');

        $this->assertNull($this->recaller($response));
        $this->assertAuthenticated();

        Log::shouldHaveReceived('warning')->once();
    }
}
