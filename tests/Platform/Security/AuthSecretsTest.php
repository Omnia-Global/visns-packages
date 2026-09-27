<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Security;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Visnsstudio\VisnsPackages\Models\TwoFactorRememberToken;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\ContactEmailResetResolver;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * 4.17.4: "remember this device" is proven by a secret the device holds, and a
 * password reset code is stored hashed, expires, and goes to the account.
 */
class AuthSecretsTest extends TestCase
{
    private string $secret;

    private function asProduction(): void
    {
        $this->app->detectEnvironment(fn() => 'production');
        $this->withoutMiddleware(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class
        );
    }

    private function totpUser(): User
    {
        $this->secret = (new Google2FA())->generateSecretKey();

        return User::create([
            'firstname' => 'Jo',
            'email' => 'jo@example.test',
            'password' => Hash::make('correct-horse'),
            'two_factor_secret' => encrypt($this->secret),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function login()
    {
        return $this->postJson('/login/authenticate', [
            'email' => 'jo@example.test',
            'password' => 'correct-horse',
        ]);
    }

    /** The device identifier the controller derives for a test request. */
    private function testDeviceIdentifier(): string
    {
        return hash('sha256', 'Symfony' . '127.0.0.1');
    }

    /*
    |--------------------------------------------------------------------------
    | Two-factor remember device
    |--------------------------------------------------------------------------
    */

    /**
     * The TOTP challenge validates through the package's own User model, whose
     * auditing trait is not installed in this harness, so issuance is proved
     * on the code driver with remember_device on - the same rememberDevice()
     * both drivers call.
     */
    private function asCodeDriverRemembering(): void
    {
        config()->set('visns-packages.auth.two_factor.driver', 'code');
        config()->set('visns-packages.auth.two_factor.remember_device', true);
        config()->set(
            'visns-packages.auth.two_factor.sender',
            \Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\CollectingCodeSender::class
        );
        \Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\CollectingCodeSender::reset();
        $this->app->bind(
            \Visnsstudio\VisnsPackages\Contracts\TwoFactorCodeSender::class,
            \Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\CollectingCodeSender::class
        );
    }

    #[Test]
    public function remembering_a_device_sets_an_httponly_cookie_and_stores_only_its_hash(): void
    {
        $this->asProduction();
        $this->asCodeDriverRemembering();
        $user = User::create([
            'email' => 'jo@example.test',
            'mobile' => '0412345678',
            'password' => Hash::make('correct-horse'),
        ]);

        $this->login()->assertJsonPath('requires_two_factor', true);

        $response = $this->postJson('/login/two-factor-challenge', [
            'code' => \Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\CollectingCodeSender::lastCode(),
            'remember' => true,
        ])->assertJsonPath('user.id', $user->id);

        $cookie = $response->getCookie(TwoFactorRememberToken::COOKIE_NAME, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));

        $plain = \Illuminate\Cookie\CookieValuePrefix::remove(
            decrypt($cookie->getValue(), false)
        );
        $row = TwoFactorRememberToken::first();

        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertSame(hash('sha256', $plain), $row->token);
        $this->assertNotSame($plain, $row->token);
    }

    #[Test]
    public function the_api_challenge_returns_the_token_to_keep(): void
    {
        $this->asProduction();
        $this->asCodeDriverRemembering();
        $user = User::create([
            'email' => 'jo@example.test',
            'mobile' => '0412345678',
            'password' => Hash::make('correct-horse'),
        ]);

        $this->postJson('/api/login', [
            'username' => 'jo@example.test',
            'password' => 'correct-horse',
        ])->assertJsonPath('two_factor_required', true);

        $token = $this->postJson('/api/two-factor-challenge', [
            'user_id' => $user->id,
            'code' => \Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\CollectingCodeSender::lastCode(),
            'remember' => true,
            'device_identifier' => 'my-phone',
        ])->assertOk()->json('two_factor_remember_token');

        $this->assertIsString($token);
        $this->assertSame(hash('sha256', $token), TwoFactorRememberToken::first()->token);
    }

    #[Test]
    public function a_remembered_device_is_recognised_by_its_cookie(): void
    {
        $this->asProduction();
        $user = $this->totpUser();
        $plain = TwoFactorRememberToken::createToken($user, $this->testDeviceIdentifier());

        $this->withCredentials()->withCookie(TwoFactorRememberToken::COOKIE_NAME, $plain)
            ->postJson('/login/authenticate', [
                'email' => 'jo@example.test',
                'password' => 'correct-horse',
            ])
            ->assertJsonPath('requires_two_factor', false)
            ->assertJsonPath('user.id', $user->id);
    }

    #[Test]
    public function a_matching_user_agent_and_ip_alone_do_not_skip_two_factor(): void
    {
        $this->asProduction();
        $user = $this->totpUser();

        // A row whose device identifier matches this very request exactly.
        TwoFactorRememberToken::createToken($user, $this->testDeviceIdentifier());

        $this->login()->assertJsonPath('requires_two_factor', true);

        // Nor does a guessed or foreign token.
        $this->withCredentials()->withCookie(TwoFactorRememberToken::COOKIE_NAME, str_repeat('a', 64))
            ->postJson('/login/authenticate', [
                'email' => 'jo@example.test',
                'password' => 'correct-horse',
            ])
            ->assertJsonPath('requires_two_factor', true);

        $this->assertNull(TwoFactorRememberToken::findValidTokenByDevice(
            $user,
            $this->testDeviceIdentifier()
        ));
    }

    #[Test]
    public function another_users_token_does_not_remember_this_user(): void
    {
        $this->asProduction();
        $this->totpUser();
        $other = User::create(['email' => 'other@example.test', 'password' => Hash::make('x')]);
        $plain = TwoFactorRememberToken::createToken($other, $this->testDeviceIdentifier());

        $this->withCredentials()->withCookie(TwoFactorRememberToken::COOKIE_NAME, $plain)
            ->postJson('/login/authenticate', [
                'email' => 'jo@example.test',
                'password' => 'correct-horse',
            ])
            ->assertJsonPath('requires_two_factor', true);
    }

    #[Test]
    public function an_expired_token_is_not_recognised(): void
    {
        $this->asProduction();
        $user = $this->totpUser();
        $plain = TwoFactorRememberToken::createToken($user, $this->testDeviceIdentifier());
        TwoFactorRememberToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->withCredentials()->withCookie(TwoFactorRememberToken::COOKIE_NAME, $plain)
            ->postJson('/login/authenticate', [
                'email' => 'jo@example.test',
                'password' => 'correct-horse',
            ])
            ->assertJsonPath('requires_two_factor', true);
    }

    #[Test]
    public function the_api_login_needs_the_token_not_just_the_device_identifier(): void
    {
        $this->asProduction();
        $user = $this->totpUser();
        $token = TwoFactorRememberToken::createToken($user, 'my-phone');

        // Device identifier alone: still challenged.
        $this->postJson('/api/login', [
            'username' => 'jo@example.test',
            'password' => 'correct-horse',
            'device_identifier' => 'my-phone',
        ])->assertJsonPath('two_factor_required', true);

        // The token, from a different device identifier: still challenged.
        $this->postJson('/api/login', [
            'username' => 'jo@example.test',
            'password' => 'correct-horse',
            'device_identifier' => 'someone-else',
            'two_factor_remember_token' => $token,
        ])->assertJsonPath('two_factor_required', true);

        // The token from the device it was issued to: through.
        $this->postJson('/api/login', [
            'username' => 'jo@example.test',
            'password' => 'correct-horse',
            'device_identifier' => 'my-phone',
            'two_factor_remember_token' => $token,
        ])->assertJsonMissingPath('two_factor_required')->assertJsonStructure(['id']);
    }

    #[Test]
    public function disabling_two_factor_forgets_every_remembered_device(): void
    {
        $user = $this->totpUser();
        TwoFactorRememberToken::createToken($user, 'a');
        TwoFactorRememberToken::createToken($user, 'b');

        $this->actingAs($user);

        (new \Visnsstudio\VisnsPackages\Controllers\UserController())
            ->disableTwoFactorAuth(request());

        $this->assertSame(0, TwoFactorRememberToken::count());
    }

    /*
    |--------------------------------------------------------------------------
    | Password reset
    |--------------------------------------------------------------------------
    */

    private array $mailed = [];

    private function captureResetMail(): void
    {
        config()->set('visns-packages.auth.mail_to_dev', 'dev@example.test');
        config()->set('visns-packages.auth.app_url', 'https://crm.example.test');
        $this->mailed = [];
        config()->set(
            'visns-packages.auth.reset_mail_factory',
            function ($content, $subject) {
                $this->mailed[] = $content;

                return new class($content) extends Mailable {
                    public function __construct(public string $body) {}

                    public function build()
                    {
                        return $this->html($this->body)->subject('Reset');
                    }
                };
            }
        );
        Mail::fake();
    }

    private function mailedToken(): string
    {
        preg_match('~/verify/([A-Za-z0-9]{60})~', (string) end($this->mailed), $m);
        $this->assertNotEmpty($m, 'No reset code was mailed.');

        return $m[1];
    }

    private function reset(string $code)
    {
        return $this->postJson('/password/reset', [
            'code' => $code,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);
    }

    #[Test]
    public function the_reset_code_is_stored_only_as_its_hash(): void
    {
        $this->captureResetMail();
        User::create(['email' => 'jo@example.test', 'password' => Hash::make('old')]);

        $this->postJson('/password/forgot', ['email' => 'jo@example.test'])
            ->assertExactJson(['error' => '']);

        $token = $this->mailedToken();
        $stored = DB::table('password_resets')->value('token');

        $this->assertSame(hash('sha256', $token), $stored);

        // The stored value itself is not a working code.
        $this->reset($stored)->assertJsonPath(
            'error',
            'The token is no longer valid, please start the password request process again.'
        );
    }

    #[Test]
    public function a_reset_code_works_inside_its_lifetime_and_not_after(): void
    {
        $this->captureResetMail();
        $user = User::create(['email' => 'jo@example.test', 'password' => Hash::make('old')]);

        $this->postJson('/password/forgot', ['email' => 'jo@example.test']);
        $token = $this->mailedToken();

        Carbon::setTestNow(now()->addMinutes(61));

        $this->reset($token)->assertJsonPath(
            'error',
            'The token is no longer valid, please start the password request process again.'
        );
        $this->assertTrue(Hash::check('old', $user->fresh()->password));
        $this->assertSame(0, DB::table('password_resets')->count());

        Carbon::setTestNow();

        $this->postJson('/password/forgot', ['email' => 'jo@example.test']);
        Carbon::setTestNow(now()->addMinutes(59));

        $this->reset($this->mailedToken())->assertJsonPath('error', '');
        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));

        Carbon::setTestNow();
    }

    #[Test]
    public function the_lifetime_is_configurable(): void
    {
        $this->captureResetMail();
        config()->set('visns-packages.auth.reset_expire_minutes', 5);
        User::create(['email' => 'jo@example.test', 'password' => Hash::make('old')]);

        $this->postJson('/password/forgot', ['email' => 'jo@example.test']);
        Carbon::setTestNow(now()->addMinutes(6));

        $this->reset($this->mailedToken())->assertJsonPath(
            'error',
            'The token is no longer valid, please start the password request process again.'
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function in_production_the_link_is_mailed_to_the_accounts_own_address(): void
    {
        $this->asProduction();
        $this->captureResetMail();
        config()->set('visns-packages.auth.reset_user_resolver', ContactEmailResetResolver::class);

        User::create([
            'username' => 'jbloggs',
            'email' => 'jo@example.test',
            'password' => Hash::make('old'),
        ]);

        $this->postJson('/password/forgot', ['email' => 'contact+jbloggs@example.test'])
            ->assertJsonPath('error', '');

        Mail::assertSent(Mailable::class, fn($m) => $m->hasTo('jo@example.test'));
        Mail::assertNotSent(Mailable::class, fn($m) => $m->hasTo('contact+jbloggs@example.test'));
    }

    #[Test]
    public function a_successful_reset_spends_every_code_and_cycles_the_remember_token(): void
    {
        $this->captureResetMail();
        $user = User::create([
            'email' => 'jo@example.test',
            'password' => Hash::make('old'),
            'remember_token' => 'old-recaller-token',
        ]);
        TwoFactorRememberToken::createToken($user, 'device');

        $this->postJson('/password/forgot', ['email' => 'jo@example.test']);
        $first = $this->mailedToken();
        $this->postJson('/password/forgot', ['email' => 'jo@example.test']);
        $second = $this->mailedToken();

        $this->assertSame(2, DB::table('password_resets')->count());

        $this->reset($second)->assertJsonPath('error', '');

        $this->assertSame(0, DB::table('password_resets')->count());
        $this->assertNotSame('old-recaller-token', $user->fresh()->remember_token);
        $this->assertSame(0, TwoFactorRememberToken::count());

        $this->reset($first)->assertJsonPath(
            'error',
            'The token is no longer valid, please start the password request process again.'
        );
    }

    #[Test]
    public function an_unknown_address_still_answers_as_before(): void
    {
        $this->captureResetMail();

        $this->postJson('/password/forgot', ['email' => 'nobody@example.test'])
            ->assertOk()
            ->assertExactJson([
                'error' => 'The email address is not found, please try again.',
            ]);
    }
}
