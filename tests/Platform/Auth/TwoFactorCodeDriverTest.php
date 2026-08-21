<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Visnsstudio\VisnsPackages\Contracts\TwoFactorCodeSender;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\CollectingCodeSender;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The code-channel two-factor driver, end to end.
 *
 * The trigger rules only ever fire in production, so most of these tests run
 * the application as production - which is the point: a test that only proved
 * the local behaviour would prove nothing about the flow real users meet.
 */
class TwoFactorCodeDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CollectingCodeSender::reset();

        config()->set('visns-packages.auth.two_factor.driver', 'code');
        config()->set(
            'visns-packages.auth.two_factor.sender',
            CollectingCodeSender::class
        );

        $this->app->bind(TwoFactorCodeSender::class, CollectingCodeSender::class);
    }

    /**
     * Run the rest of the test as production.
     *
     * The two-factor trigger is deliberately production-only, so this is the
     * only way to exercise it at all. CSRF has to be stood down alongside:
     * Laravel skips the check for unit tests by looking at the environment
     * name, so pretending to be production also turns the check back on and
     * every post below would 419 for reasons that have nothing to do with 2FA.
     */
    private function asProduction(): void
    {
        $this->app->detectEnvironment(fn() => 'production');

        $this->withoutMiddleware(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class
        );
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
    | Trigger policy
    |--------------------------------------------------------------------------
    */

    public function test_no_challenge_outside_production_even_with_trigger_always(): void
    {
        $this->user();

        $this->login()
            ->assertJsonPath('requires_two_factor', false)
            ->assertJsonPath('error', '');

        $this->assertSame([], CollectingCodeSender::$sent);
        $this->assertAuthenticated();
    }

    public function test_trigger_always_challenges_in_production(): void
    {
        $this->asProduction();
        $user = $this->user();

        $this->login()
            ->assertOk()
            ->assertJsonPath('error', '')
            ->assertJsonPath('requires_two_factor', true)
            ->assertJsonPath('user', null);

        // Crucially NOT logged in: the challenge has to be answered first.
        $this->assertGuest();

        $this->assertCount(1, CollectingCodeSender::$sent);
        $this->assertSame($user->id, CollectingCodeSender::$sent[0]['user_id']);

        $user->refresh();
        $this->assertNotNull($user->two_factor_token);
        $this->assertNotNull($user->two_factor_token_sent_at);
    }

    public function test_trigger_never_lets_the_login_straight_through(): void
    {
        $this->asProduction();
        config()->set('visns-packages.auth.two_factor.trigger', 'never');

        $this->user();

        $this->login()->assertJsonPath('requires_two_factor', false);

        $this->assertSame([], CollectingCodeSender::$sent);
        $this->assertAuthenticated();
    }

    public function test_trigger_ip_change_only_fires_from_an_unfamiliar_address(): void
    {
        $this->asProduction();
        config()->set('visns-packages.auth.two_factor.trigger', 'ip_change');

        // Laravel's test client reports 127.0.0.1.
        $this->user(['last_logged_ip_address' => '127.0.0.1']);

        $this->login()->assertJsonPath('requires_two_factor', false);
        $this->assertSame([], CollectingCodeSender::$sent);
    }

    public function test_trigger_ip_change_fires_when_the_address_differs(): void
    {
        $this->asProduction();
        config()->set('visns-packages.auth.two_factor.trigger', 'ip_change');

        $this->user(['last_logged_ip_address' => '203.0.113.9']);

        $this->login()->assertJsonPath('requires_two_factor', true);
        $this->assertCount(1, CollectingCodeSender::$sent);
    }

    /*
    |--------------------------------------------------------------------------
    | The code itself
    |--------------------------------------------------------------------------
    */

    public function test_the_code_is_six_digits_and_may_start_with_zero(): void
    {
        $this->asProduction();
        $user = $this->user();

        // Zero-padding is not cosmetic: a code drawn as 000123 must still be
        // six characters, or a naive integer round trip silently shortens it.
        for ($i = 0; $i < 25; $i++) {
            $this->login();
            $this->assertMatchesRegularExpression(
                '/^\d{6}$/',
                CollectingCodeSender::lastCode()
            );
        }
    }

    public function test_the_message_renders_the_template_and_the_autofill_suffix(): void
    {
        $this->asProduction();
        config()->set(
            'visns-packages.auth.two_factor.message_template',
            'Your OFP code is {code}.'
        );

        $this->user();
        $this->login();

        $code = CollectingCodeSender::lastCode();
        $message = CollectingCodeSender::$sent[0]['message'];

        $this->assertStringStartsWith("Your OFP code is {$code}.", $message);
        // The trailer is what lets iOS/Android offer the code for autofill.
        $this->assertStringEndsWith("\n\n@crm.example.test #{$code}", $message);
    }

    public function test_the_autofill_suffix_can_be_switched_off(): void
    {
        $this->asProduction();
        config()->set('visns-packages.auth.two_factor.append_autofill_suffix', false);

        $this->user();
        $this->login();

        $this->assertStringNotContainsString(
            '@crm.example.test',
            CollectingCodeSender::$sent[0]['message']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Completing the challenge
    |--------------------------------------------------------------------------
    */

    public function test_a_correct_code_completes_the_login_and_consumes_the_code(): void
    {
        $this->asProduction();
        $user = $this->user();

        $this->login(['location' => '/clients/7']);
        $code = CollectingCodeSender::lastCode();

        $this->postJson('/login/two-factor-challenge', [
            'code' => $code,
            'previous_url' => '/clients/7',
        ])
            ->assertOk()
            ->assertJsonPath('error', '')
            ->assertJsonPath('previous', '/clients/7')
            ->assertJsonPath('user.id', $user->id);

        $this->assertAuthenticated();

        // Single use: the code is gone the moment it works, so an intercepted
        // SMS cannot be replayed.
        $user->refresh();
        $this->assertNull($user->two_factor_token);
        $this->assertNull($user->two_factor_token_sent_at);
    }

    public function test_a_consumed_code_cannot_be_replayed(): void
    {
        $this->asProduction();
        $this->user();

        $this->login();
        $code = CollectingCodeSender::lastCode();

        $this->postJson('/login/two-factor-challenge', ['code' => $code]);

        // Fresh session, same code.
        $this->flushSession();
        $this->login();

        $this->postJson('/login/two-factor-challenge', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('user', null);
    }

    public function test_a_wrong_code_is_refused_with_a_200_body(): void
    {
        $this->asProduction();
        $this->user();

        $this->login();

        // 200, not 401: the front end reads `error` off the body here, and a
        // 401 would trip its session-expired interceptor and throw the user off
        // the challenge screen.
        $this->postJson('/login/two-factor-challenge', ['code' => '000000'])
            ->assertOk()
            ->assertJsonPath(
                'error',
                'The provided two-factor authentication code was invalid.'
            )
            ->assertJsonPath('user', null);

        $this->assertGuest();
    }

    public function test_an_expired_code_is_refused_with_its_own_message(): void
    {
        $this->asProduction();
        config()->set('visns-packages.auth.two_factor.expiry_minutes', 15);

        $user = $this->user();

        $this->login();
        $code = CollectingCodeSender::lastCode();

        $user->forceFill([
            'two_factor_token_sent_at' => now()->subMinutes(16),
        ])->save();

        $this->postJson('/login/two-factor-challenge', ['code' => $code])
            ->assertOk()
            ->assertJsonPath(
                'error',
                'The verification code has expired, please request a new one.'
            );

        $this->assertGuest();
    }

    public function test_a_code_just_inside_the_window_still_works(): void
    {
        $this->asProduction();
        $user = $this->user();

        $this->login();
        $code = CollectingCodeSender::lastCode();

        $user->forceFill([
            'two_factor_token_sent_at' => now()->subMinutes(14),
        ])->save();

        $this->postJson('/login/two-factor-challenge', ['code' => $code])
            ->assertJsonPath('error', '');

        $this->assertAuthenticated();
    }

    public function test_the_challenge_needs_a_session_started_by_a_login(): void
    {
        $this->asProduction();
        $this->user();

        $this->postJson('/login/two-factor-challenge', ['code' => '123456'])
            ->assertOk()
            ->assertJsonPath(
                'error',
                'Invalid two-factor authentication session.'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Resend
    |--------------------------------------------------------------------------
    */

    public function test_resend_issues_a_fresh_code_and_invalidates_the_old_one(): void
    {
        $this->asProduction();
        $this->user();

        $this->login();
        $first = CollectingCodeSender::lastCode();

        $this->postJson('/login/two-factor-resend')
            ->assertOk()
            ->assertJsonPath('error', '');

        $second = CollectingCodeSender::lastCode();

        $this->assertCount(2, CollectingCodeSender::$sent);

        // The old code must stop working - otherwise every resend widens the
        // set of codes an attacker may guess.
        $this->postJson('/login/two-factor-challenge', ['code' => $first])
            ->assertJsonPath('user', null);

        $this->postJson('/login/two-factor-challenge', ['code' => $second])
            ->assertJsonPath('error', '');
    }

    public function test_resend_refuses_without_a_challenge_in_flight(): void
    {
        $this->asProduction();
        $this->user();

        $this->postJson('/login/two-factor-resend')
            ->assertJsonPath(
                'error',
                'Invalid two-factor authentication session.'
            );

        $this->assertSame([], CollectingCodeSender::$sent);
    }

    public function test_resend_refuses_when_the_channel_is_turned_off(): void
    {
        $this->asProduction();
        config()->set('visns-packages.auth.two_factor.trigger', 'never');

        $this->user();

        $this->postJson('/login/two-factor-resend')->assertJsonPath(
            'error',
            'Invalid two-factor authentication session.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Delivery failure
    |--------------------------------------------------------------------------
    */

    public function test_a_login_is_refused_when_the_code_cannot_be_delivered(): void
    {
        $this->asProduction();
        CollectingCodeSender::$shouldFail = true;

        $this->user();

        $this->login()
            ->assertOk()
            ->assertJsonPath(
                'error',
                'The verification code could not be sent, please try again.'
            )
            ->assertJsonPath('requires_two_factor', false);

        // A code that never left the building must not become a free pass.
        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | The TOTP driver is untouched
    |--------------------------------------------------------------------------
    */

    public function test_the_shipped_default_driver_is_totp(): void
    {
        // Read from the config file the package actually publishes: an
        // application that adopts this release without touching its config must
        // keep the authenticator-app flow it already has.
        $shipped = require __DIR__ . '/../../../config/visns-packages.php';

        $this->assertSame('totp', $shipped['auth']['two_factor']['driver']);
        $this->assertSame('always', $shipped['auth']['two_factor']['trigger']);
        $this->assertFalse($shipped['auth']['two_factor']['remember_device']);
    }

    public function test_a_totp_user_is_challenged_in_production_and_gets_the_user_back(): void
    {
        config()->set('visns-packages.auth.two_factor.driver', 'totp');
        $this->asProduction();

        $user = $this->user([
            'two_factor_secret' => encrypt('SECRETSECRETSECR'),
            'two_factor_confirmed_at' => now(),
        ]);

        // The TOTP challenge has always echoed the (unloaded) user model back,
        // because the challenge screen renders the account it is challenging.
        $this->login()
            ->assertJsonPath('requires_two_factor', true)
            ->assertJsonPath('user.id', $user->id);

        $this->assertSame([], CollectingCodeSender::$sent);
    }
}
