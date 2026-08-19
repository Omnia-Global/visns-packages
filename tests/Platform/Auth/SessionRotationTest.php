<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Visnsstudio\VisnsPackages\Contracts\TwoFactorCodeSender;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\CollectingCodeSender;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * What a login is allowed to rotate, and what it must leave alone.
 *
 * The session ID must rotate at every privilege change — that is the fixation
 * defence. The CSRF token must NOT, because the SPA does not reload across a 2FA
 * challenge: it keeps the <meta csrf-token> it rendered before the challenge, so
 * rolling the token strands the open page and every POST it makes afterwards
 * 419s. GETs keep working, which is exactly what made the bug look intermittent.
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

    /*
    |--------------------------------------------------------------------------
    | The regression
    |--------------------------------------------------------------------------
    */

    public function test_completing_a_code_challenge_does_not_roll_the_csrf_token(): void
    {
        $this->useCodeDriver();
        $this->user();

        $this->login()->assertJsonPath('requires_two_factor', true);

        // The token the SPA page is holding while it shows the code prompt.
        $tokenAtChallenge = $this->store()->token();
        $this->assertNotEmpty($tokenAtChallenge);

        $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
        ])->assertJsonPath('error', '');

        // THE regression: a rolled token here is what 419'd every subsequent
        // POST from the still-open page.
        $this->assertSame($tokenAtChallenge, $this->store()->token());
    }

    public function test_the_page_can_keep_posting_after_a_code_challenge(): void
    {
        $this->useCodeDriver();
        $this->user();

        $this->login();

        // Same as above, but proving the consequence rather than the mechanism:
        // a POST carrying the token the page rendered BEFORE the challenge must
        // still be accepted afterwards.
        $tokenAtChallenge = $this->store()->token();

        $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
        ])->assertJsonPath('error', '');

        // CSRF verification back on for the request that matters - without this
        // the assertion below would pass no matter what the token did.
        $this->withMiddleware(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class
        );

        $this->post('/login/two-factor-resend', [
            '_token' => $tokenAtChallenge,
        ])->assertOk();
    }

    public function test_completing_a_totp_challenge_does_not_roll_the_csrf_token(): void
    {
        config()->set('visns-packages.auth.two_factor.driver', 'totp');

        $user = $this->user([
            'two_factor_secret' => encrypt('SECRETSECRETSECR'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->app->detectEnvironment(fn() => 'production');
        $this->withoutMiddleware(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class
        );

        $this->login()->assertJsonPath('requires_two_factor', true);

        $tokenAtChallenge = $this->store()->token();

        // Outside production the TOTP endpoint completes without a code, which
        // exercises completeLogin() without standing up an authenticator.
        $this->app->detectEnvironment(fn() => 'testing');

        $this->postJson('/login/two-factor-challenge', [])
            ->assertJsonPath('user.id', $user->id);

        $this->assertSame($tokenAtChallenge, $this->store()->token());
    }

    /*
    |--------------------------------------------------------------------------
    | The fixation guarantee is intact
    |--------------------------------------------------------------------------
    */

    public function test_the_session_id_still_rotates_on_a_plain_login(): void
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

    public function test_the_session_id_still_rotates_when_a_challenge_completes(): void
    {
        $this->useCodeDriver();
        $this->user();

        $this->login();

        // The id an attacker could have planted before the challenge.
        $idAtChallenge = $this->store()->getId();

        $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
        ])->assertJsonPath('error', '');

        // Dropping regenerate() must not have cost the fixation defence:
        // Auth::login() rotates the id via migrate(true).
        $this->assertNotSame(
            $idAtChallenge,
            $this->store()->getId(),
            'the session id did not rotate when the challenge completed'
        );
        $this->assertAuthenticated();
    }

    /*
    |--------------------------------------------------------------------------
    | csrf_token in the responses
    |--------------------------------------------------------------------------
    */

    public function test_a_plain_login_returns_the_live_csrf_token(): void
    {
        $this->user();

        $response = $this->login()->assertJsonPath('error', '');

        $this->assertSame(
            $this->store()->token(),
            $response->json('csrf_token')
        );
    }

    public function test_the_requires_two_factor_response_returns_the_token(): void
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

    public function test_the_challenge_completion_returns_the_token(): void
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

    public function test_the_token_returned_across_the_challenge_is_the_same_one(): void
    {
        $this->useCodeDriver();
        $this->user();

        $atChallenge = $this->login()->json('csrf_token');

        $afterChallenge = $this->postJson('/login/two-factor-challenge', [
            'code' => CollectingCodeSender::lastCode(),
        ])->json('csrf_token');

        // A frontend that resyncs from this field sees no change - which is the
        // point: there is nothing to resync, because nothing rotated.
        $this->assertSame($atChallenge, $afterChallenge);
    }

    public function test_a_failed_login_still_returns_a_token(): void
    {
        $this->user();

        // The login screen stays open on a bad password, so it still needs a
        // usable token for the next attempt.
        $response = $this->login(['password' => 'wrong'])
            ->assertJsonPath('error', 'Login unsuccessful, please try again.');

        $this->assertNotEmpty($response->json('csrf_token'));
    }
}
