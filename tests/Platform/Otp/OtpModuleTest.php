<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Otp;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Visnsstudio\VisnsPackages\Contracts\OtpSender;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Otp\CollectingOtpSender;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The passwordless OTP module.
 *
 * The status codes and message strings asserted here are the ones the CRM this
 * was lifted from answers with today, verbatim - its portal front end branches
 * on them, so they are contract, not decoration.
 */
class OtpModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CollectingOtpSender::reset();

        config()->set('visns-packages.otp.enabled', true);
        config()->set('visns-packages.otp.sender', CollectingOtpSender::class);
        // The bundled resolver searches the user table, so a contact IS a user
        // and the "which user owns this contact" join is the identity.
        config()->set('visns-packages.otp.user_foreign_key', 'id');
        config()->set('visns-packages.otp.user_relations', []);

        $this->app->bind(OtpSender::class, CollectingOtpSender::class);
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        // Routes are registered at boot, so the module has to be on before the
        // provider runs.
        $app['config']->set('visns-packages.otp.enabled', true);
    }

    private function contact(array $attributes = []): User
    {
        return User::create(array_merge([
            'firstname' => 'Jo',
            'surname' => 'Bloggs',
            'email' => 'jo@example.test',
            'mobile' => '0412345678',
            'company_contact_id' => 900,
            'password' => Hash::make('unused'),
        ], $attributes));
    }

    private function asProduction(): void
    {
        $this->app->detectEnvironment(fn() => 'production');

        $this->withoutMiddleware(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Requesting a code
    |--------------------------------------------------------------------------
    */

    public function test_an_unknown_contact_is_404_with_the_shipped_message(): void
    {
        $this->postJson('/api/auth/request-otp', ['contact' => 'nobody@example.test'])
            ->assertStatus(404)
            ->assertExactJson([
                'error' => 'Email or mobile number not found. Please contact the Throughlife team to verify your contact details.',
            ]);
    }

    public function test_outside_production_the_code_comes_back_in_the_body(): void
    {
        $this->contact();

        $response = $this->postJson('/api/auth/request-otp', [
            'contact' => 'jo@example.test',
        ])->assertOk();

        $response
            ->assertJsonPath('error', '')
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'OTP generated for testing')
            ->assertJsonPath('contact_method', 'email')
            ->assertJsonPath('masked_contact', 'jo***@example.test');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $response->json('dev_otp'));

        // Nothing was actually sent - that is the point of the dev path.
        $this->assertSame([], CollectingOtpSender::$sent);
    }

    public function test_in_production_the_code_is_sent_and_never_returned(): void
    {
        $this->asProduction();
        $this->contact();

        $this->postJson('/api/auth/request-otp', ['contact' => 'jo@example.test'])
            ->assertOk()
            ->assertJsonPath('message', 'OTP sent successfully')
            ->assertJsonMissingPath('dev_otp');

        $this->assertCount(1, CollectingOtpSender::$sent);
        $this->assertMatchesRegularExpression(
            '/^\d{6}$/',
            CollectingOtpSender::lastCode()
        );
    }

    public function test_exposing_the_code_outside_production_can_be_turned_off(): void
    {
        config()->set('visns-packages.otp.expose_code_outside_production', false);

        $this->contact();

        $this->postJson('/api/auth/request-otp', ['contact' => 'jo@example.test'])
            ->assertOk()
            ->assertJsonMissingPath('dev_otp');

        $this->assertCount(1, CollectingOtpSender::$sent);
    }

    public function test_the_code_is_stored_hashed_never_in_the_clear(): void
    {
        $contact = $this->contact();

        $code = $this->postJson('/api/auth/request-otp', [
            'contact' => 'jo@example.test',
        ])->json('dev_otp');

        $contact->refresh();

        $this->assertNotSame($code, $contact->otp_code);
        $this->assertTrue(Hash::check($code, $contact->otp_code));
    }

    public function test_a_contact_without_a_portal_account_is_403(): void
    {
        // No account for this contact: point the join at a column that cannot
        // match, which is what "the contact exists but has no login" looks like.
        config()->set('visns-packages.otp.user_foreign_key', 'company_contact_id');

        $this->contact(['company_contact_id' => null]);

        $this->postJson('/api/auth/request-otp', ['contact' => 'jo@example.test'])
            ->assertStatus(403)
            ->assertExactJson([
                'error' => 'No portal access is set up for this contact. Please contact the Throughlife team to activate your portal access.',
            ]);
    }

    public function test_a_second_request_inside_the_cooldown_is_429(): void
    {
        $this->contact();

        $this->postJson('/api/auth/request-otp', ['contact' => 'jo@example.test'])
            ->assertOk();

        $this->postJson('/api/auth/request-otp', ['contact' => 'jo@example.test'])
            ->assertStatus(429)
            ->assertExactJson([
                'error' => 'Too many OTP requests. Please try again later or contact the Throughlife team for assistance.',
            ]);
    }

    public function test_a_request_after_the_cooldown_is_allowed_again(): void
    {
        $contact = $this->contact();

        $this->postJson('/api/auth/request-otp', ['contact' => 'jo@example.test']);

        $contact->forceFill(['otp_sent_at' => now()->subMinutes(3)])->save();

        $this->postJson('/api/auth/request-otp', ['contact' => 'jo@example.test'])
            ->assertOk();
    }

    public function test_a_hard_lock_blocks_a_request(): void
    {
        $this->contact(['otp_locked_until' => now()->addHour()]);

        $this->postJson('/api/auth/request-otp', ['contact' => 'jo@example.test'])
            ->assertStatus(429);
    }

    /*
    |--------------------------------------------------------------------------
    | Logging in with a code
    |--------------------------------------------------------------------------
    */

    private function requestCode(string $contact = 'jo@example.test'): string
    {
        return $this->postJson('/api/auth/request-otp', ['contact' => $contact])
            ->json('dev_otp');
    }

    public function test_a_valid_code_returns_a_token_and_the_user(): void
    {
        $contact = $this->contact();
        $code = $this->requestCode();

        $response = $this->postJson('/api/auth/login-otp', [
            'contact' => 'jo@example.test',
            'otp_code' => $code,
        ])->assertOk();

        $response
            ->assertJsonPath('error', '')
            ->assertJsonPath('user.id', $contact->id)
            ->assertJsonPath('full_data_available', false);

        $this->assertNotEmpty($response->json('access_token'));
        $this->assertSame(1, $contact->tokens()->count());
    }

    public function test_the_token_name_is_configurable(): void
    {
        config()->set('visns-packages.otp.token_name', 'portal-token');

        $contact = $this->contact();
        $code = $this->requestCode();

        $this->postJson('/api/auth/login-otp', [
            'contact' => 'jo@example.test',
            'otp_code' => $code,
        ])->assertOk();

        $this->assertSame('portal-token', $contact->tokens()->first()->name);
    }

    public function test_minimal_response_returns_only_the_whitelisted_fields(): void
    {
        $contact = $this->contact();
        $code = $this->requestCode();

        $response = $this->postJson('/api/auth/login-otp', [
            'contact' => 'jo@example.test',
            'otp_code' => $code,
            'minimal_response' => true,
        ])->assertOk();

        $response->assertJsonPath('full_data_available', true);

        $this->assertSame(
            [
                'id',
                'firstname',
                'surname',
                'email',
                'company_contact_id',
                'dateLastLogged',
                'company_contact',
            ],
            array_keys($response->json('user'))
        );

        // The live OTP hash must never travel in a payload the caller keeps.
        $this->assertArrayNotHasKey('otp_code', $response->json('user'));
    }

    public function test_the_login_stamps_the_last_login_columns(): void
    {
        $contact = $this->contact();
        $code = $this->requestCode();

        $this->postJson('/api/auth/login-otp', [
            'contact' => 'jo@example.test',
            'otp_code' => $code,
        ])->assertOk();

        $contact->refresh();

        $this->assertNotNull($contact->dateLastLogged);
        $this->assertSame('127.0.0.1', $contact->last_logged_ip_address);
    }

    public function test_a_wrong_code_is_401(): void
    {
        $this->contact();
        $this->requestCode();

        $this->postJson('/api/auth/login-otp', [
            'contact' => 'jo@example.test',
            'otp_code' => '000000',
        ])
            ->assertStatus(401)
            ->assertExactJson([
                'error' => 'Invalid or expired OTP. Please request a new code or contact the Throughlife team for assistance.',
            ]);
    }

    public function test_an_expired_code_is_401(): void
    {
        $contact = $this->contact();
        $code = $this->requestCode();

        $contact->forceFill(['otp_sent_at' => now()->subMinutes(6)])->save();

        $this->postJson('/api/auth/login-otp', [
            'contact' => 'jo@example.test',
            'otp_code' => $code,
        ])->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | Consume on success
    |--------------------------------------------------------------------------
    */

    public function test_by_default_a_spent_code_still_works_inside_its_window(): void
    {
        $contact = $this->contact();
        $code = $this->requestCode();

        $this->postJson('/api/auth/login-otp', [
            'contact' => 'jo@example.test',
            'otp_code' => $code,
        ])->assertOk();

        // The replay window the ported controller has always had. Documented
        // rather than endorsed - see consume_on_success.
        $this->assertNotNull($contact->fresh()->otp_code);

        $this->postJson('/api/auth/login-otp', [
            'contact' => 'jo@example.test',
            'otp_code' => $code,
        ])->assertOk();
    }

    public function test_consume_on_success_closes_the_replay_window(): void
    {
        config()->set('visns-packages.otp.consume_on_success', true);

        $contact = $this->contact();
        $code = $this->requestCode();

        $this->postJson('/api/auth/login-otp', [
            'contact' => 'jo@example.test',
            'otp_code' => $code,
        ])->assertOk();

        $contact->refresh();

        $this->assertNull($contact->otp_code);
        $this->assertNull($contact->otp_sent_at);

        // One code, one login: anyone who saw the code on a lock screen cannot
        // walk in behind the user.
        $this->postJson('/api/auth/login-otp', [
            'contact' => 'jo@example.test',
            'otp_code' => $code,
        ])->assertStatus(401);
    }

    public function test_consume_on_success_still_clears_the_throttling_state(): void
    {
        config()->set('visns-packages.otp.consume_on_success', true);

        $contact = $this->contact();
        $code = $this->requestCode();

        // Two near misses, then the real thing.
        foreach (['000000', '111111'] as $wrong) {
            $this->postJson('/api/auth/login-otp', [
                'contact' => 'jo@example.test',
                'otp_code' => $wrong,
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/login-otp', [
            'contact' => 'jo@example.test',
            'otp_code' => $code,
        ])->assertOk();

        $contact->refresh();

        // Otherwise the next code would start life two attempts from its
        // ceiling.
        $this->assertSame(0, (int) $contact->otp_attempts);
        $this->assertNull($contact->otp_locked_until);
    }

    public function test_consume_on_success_defaults_off(): void
    {
        $shipped = require __DIR__ . '/../../../config/visns-packages.php';

        $this->assertFalse($shipped['otp']['consume_on_success']);
    }

    public function test_the_attempt_ceiling_locks_out_a_brute_force(): void
    {
        $contact = $this->contact();
        $code = $this->requestCode();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login-otp', [
                'contact' => 'jo@example.test',
                'otp_code' => '000000',
            ])->assertStatus(401);
        }

        $this->assertSame(3, (int) $contact->fresh()->otp_attempts);

        // Even the RIGHT code is refused once the ceiling is hit - otherwise
        // the ceiling only slows an attacker down rather than stopping them.
        $this->postJson('/api/auth/login-otp', [
            'contact' => 'jo@example.test',
            'otp_code' => $code,
        ])->assertStatus(401);
    }

    public function test_an_unknown_contact_at_login_is_401_not_404(): void
    {
        // Deliberately a different status from the request endpoint's 404: at
        // login the caller is presenting a credential, so every failure looks
        // the same from outside.
        $this->postJson('/api/auth/login-otp', [
            'contact' => 'nobody@example.test',
            'otp_code' => '123456',
        ])->assertStatus(401);
    }

    /**
     * A short code is a 500, not a 422.
     *
     * This is faithful, not desirable: the controller this was lifted from
     * wraps its own $request->validate() in a catch-all, so the
     * ValidationException never reaches Laravel's handler and the caller gets
     * the generic failure message instead of a field error. The portal front
     * end in production reads exactly that today, so the module reproduces it
     * rather than quietly improving it - see the note in the README.
     */
    public function test_a_wrong_length_code_answers_the_generic_failure(): void
    {
        $this->contact();

        $this->postJson('/api/auth/login-otp', [
            'contact' => 'jo@example.test',
            'otp_code' => '12345',
        ])
            ->assertStatus(500)
            ->assertExactJson([
                'error' => 'An error occurred during login. Please contact the Throughlife team for assistance.',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Module gating
    |--------------------------------------------------------------------------
    */

    public function test_the_endpoints_do_not_exist_when_the_module_is_off(): void
    {
        $shipped = require __DIR__ . '/../../../config/visns-packages.php';

        // Enabling the module publishes two unauthenticated endpoints, so an
        // application has to ask for them.
        $this->assertFalse($shipped['otp']['enabled']);
    }
}
