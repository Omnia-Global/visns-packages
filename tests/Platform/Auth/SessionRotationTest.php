<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Auth;

use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Hash;
use Visnsstudio\VisnsPackages\Contracts\TwoFactorCodeSender;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\CollectingCodeSender;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * What a login rotates, and how a single-page app is supposed to cope.
 *
 * The framework rotates the session on login, and what it rotates depends on the
 * version:
 *
 *   Laravel 11 and earlier   updateSession() -> session->migrate(true)     id only
 *   Laravel 12 and later     updateSession() -> session->regenerate(true)  id AND CSRF token
 *
 * This package fights neither. The contract it offers instead is the
 * `csrf_token` field on every stateful auth response: whatever the framework has
 * just done to the session, that field is the token the caller should now be
 * using. These tests pin THAT, because it is the only thing that holds on both
 * versions - and, on a framework that rotates, they also pin that the stale
 * pre-challenge token really is rejected, which is the failure a single-page app
 * hits if it ignores the field.
 *
 * An earlier revision of this file asserted the opposite: that the token
 * SURVIVES a challenge. It passed, because the suite then resolved Laravel 11.
 * It was wrong anyway - it pinned one version's behaviour as though it were the
 * contract, while the application consuming this package runs Laravel 12, where
 * the token does rotate and real logins were 419-ing. The suite now resolves the
 * same framework major the consumer runs; test_the_suite_runs_against... below
 * is what keeps it that way.
 */
class SessionRotationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CollectingCodeSender::reset();
    }

    /** The Store the request cycle used, so its post-request state is readable. */
    private function store()
    {
        return $this->app['session.store'];
    }

    private function csrfMiddleware(): string
    {
        return \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class;
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

    /**
     * Code-driver 2FA, in production.
     *
     * Production because the trigger is only ever evaluated there; CSRF stood
     * down because pretending to be production also switches the check back on,
     * and these requests are not the ones under test. The two tests that DO care
     * about CSRF re-enable it for the single request that matters.
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
        $this->withoutMiddleware($this->csrfMiddleware());
    }

    /*
    |--------------------------------------------------------------------------
    | What the framework under test actually does
    |--------------------------------------------------------------------------
    */

    public function test_the_suite_runs_against_the_framework_major_the_consumer_runs(): void
    {
        // Guards the whole file. Every assertion below describes Laravel >= 12
        // behaviour, so if the dev dependencies are ever resolved back onto
        // Laravel 11 this is the line that fails first, and legibly.
        $this->assertGreaterThanOrEqual(
            12,
            (int) explode('.', Application::VERSION)[0],
            'these assertions describe Laravel >= 12 session behaviour'
        );
    }

    public function test_login_rotates_the_csrf_token(): void
    {
        $this->user();

        $this->get('/logout');
        $tokenBefore = $this->store()->token();

        $this->login()->assertJsonPath('error', '');

        // Laravel 12's SessionGuard::updateSession() calls regenerate(true).
        // Deliberate framework security - a privilege change should not leave
        // the old CSRF token valid - and not ours to suppress.
        $this->assertNotSame($tokenBefore, $this->store()->token());
    }

    public function test_completing_a_code_challenge_rotates_the_csrf_token(): void
    {
        $this->useCodeDriver();
        $this->user();

        $this->login()->assertJsonPath('requires_two_factor', true);

        $tokenAtChallenge = $this->store()->token();

        $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
        ])->assertJsonPath('error', '');

        // The challenge IS the privilege change here - the authenticate() call
        // above it deliberately does not log anyone in - so the rotation lands
        // at this point rather than at the password check.
        $this->assertNotSame($tokenAtChallenge, $this->store()->token());
    }

    /*
    |--------------------------------------------------------------------------
    | The contract: csrf_token on every stateful auth response
    |--------------------------------------------------------------------------
    */

    public function test_a_plain_login_returns_the_post_login_token(): void
    {
        $this->user();

        $response = $this->login()->assertJsonPath('error', '');

        // The post-handling token, not the one the request arrived with. That
        // is the entire value of the field.
        $this->assertSame(
            $this->store()->token(),
            $response->json('csrf_token')
        );
    }

    public function test_the_requires_two_factor_response_returns_the_live_token(): void
    {
        $this->useCodeDriver();
        $this->user();

        $response = $this->login()
            ->assertJsonPath('requires_two_factor', true);

        $this->assertSame(
            $this->store()->token(),
            $response->json('csrf_token')
        );
    }

    public function test_the_challenge_completion_returns_the_post_login_token(): void
    {
        $this->useCodeDriver();
        $this->user();

        $this->login();

        $response = $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
        ])->assertJsonPath('error', '');

        $this->assertSame(
            $this->store()->token(),
            $response->json('csrf_token')
        );
    }

    public function test_the_token_returned_across_the_challenge_is_the_new_one(): void
    {
        $this->useCodeDriver();
        $this->user();

        $atChallenge = $this->login()->json('csrf_token');

        $afterChallenge = $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
        ])->json('csrf_token');

        // A frontend resyncing from this field moves off the stale token; one
        // that ignores it keeps the stale token and starts 419-ing.
        $this->assertNotSame($atChallenge, $afterChallenge);
    }

    public function test_a_failed_login_still_returns_a_usable_token(): void
    {
        $this->user();

        // The login screen stays open on a bad password, so it still needs a
        // token it can post the next attempt with.
        $response = $this->login(['password' => 'wrong'])
            ->assertJsonPath('error', 'Login unsuccessful, please try again.');

        $this->assertSame(
            $this->store()->token(),
            $response->json('csrf_token')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | End to end, with CSRF verification actually in the stack
    |--------------------------------------------------------------------------
    */

    public function test_a_post_with_the_response_provided_token_passes_csrf(): void
    {
        $this->useCodeDriver();
        $this->user();

        $this->login();

        $token = $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
        ])->assertJsonPath('error', '')->json('csrf_token');

        // CSRF back on for the request that matters. Without this the assertion
        // would hold no matter what the token did.
        $this->withMiddleware($this->csrfMiddleware());

        // Only the CSRF verdict is under test; the body is beside the point
        // (the challenge is over, so resend reports no session in flight).
        $this->post('/login/two-factor-resend', ['_token' => $token])
            ->assertOk();
    }

    public function test_a_post_with_the_stale_pre_challenge_token_is_rejected(): void
    {
        $this->useCodeDriver();
        $this->user();

        $this->login();

        // What the SPA page is still holding in its <meta csrf-token>, having
        // rendered before the challenge and never reloaded since.
        $staleToken = $this->store()->token();

        $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
        ])->assertJsonPath('error', '');

        $this->withMiddleware($this->csrfMiddleware());

        // The reported bug, reproduced as a guarantee: 419 on every POST until
        // the page reloads or resyncs from `csrf_token`.
        $this->post('/login/two-factor-resend', ['_token' => $staleToken])
            ->assertStatus(419);
    }

    /*
    |--------------------------------------------------------------------------
    | Session fixation
    |--------------------------------------------------------------------------
    */

    public function test_the_session_id_rotates_on_a_plain_login(): void
    {
        $this->user();

        $this->get('/logout');
        $idBefore = $this->store()->getId();

        $this->login()->assertJsonPath('error', '');

        $this->assertNotSame(
            $idBefore,
            $this->store()->getId(),
            'the session id did not rotate on login'
        );
        $this->assertAuthenticated();
    }

    public function test_the_session_id_rotates_when_a_challenge_completes(): void
    {
        $this->useCodeDriver();
        $this->user();

        $this->login();

        // The id an attacker could have planted before the challenge.
        $idAtChallenge = $this->store()->getId();

        $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
        ])->assertJsonPath('error', '');

        $this->assertNotSame(
            $idAtChallenge,
            $this->store()->getId(),
            'the session id did not rotate when the challenge completed'
        );
        $this->assertAuthenticated();
    }
}
