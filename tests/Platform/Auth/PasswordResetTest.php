<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\ContactEmailResetResolver;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\MirrorPasswordHook;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\PortalResetUrlBuilder;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * Password reset: the historical behaviour, and the four extension points that
 * let an application whose accounts are reachable by more than one address
 * adopt it.
 */
class PasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MirrorPasswordHook::reset();

        // Reset mail only leaves the building in production; everywhere else it
        // is funnelled to one address, so the tests below set one.
        config()->set('visns-packages.auth.mail_to_dev', 'dev@example.test');
        config()->set('visns-packages.auth.app_url', 'https://crm.example.test');
        config()->set('visns-packages.auth.front_end_url', 'https://app.example.test');
        config()->set('portal.url', 'https://portal.example.test');

        // No GenericMail exists in the test app, so the default mail tier is a
        // no-op; the tests that care about the link read it off the row/builder.
        Mail::fake();
    }

    private function user(array $attributes = []): User
    {
        return User::create(array_merge([
            'firstname' => 'Jo',
            'username' => 'jbloggs',
            'email' => 'jo@example.test',
            'password' => Hash::make('old-password'),
        ], $attributes));
    }

    /*
    |--------------------------------------------------------------------------
    | Historical behaviour
    |--------------------------------------------------------------------------
    */

    public function test_an_unknown_address_answers_the_shipped_message(): void
    {
        $this->postJson('/password/forgot', ['email' => 'nobody@example.test'])
            ->assertOk()
            ->assertExactJson([
                'error' => 'The email address is not found, please try again.',
            ]);

        $this->assertSame(0, DB::table('password_resets')->count());
    }

    public function test_a_known_address_writes_a_token_row_keyed_on_what_was_typed(): void
    {
        $this->user();

        $this->postJson('/password/forgot', ['email' => 'jo@example.test'])
            ->assertOk()
            ->assertExactJson(['error' => '']);

        $row = DB::table('password_resets')->first();

        $this->assertSame('jo@example.test', $row->email);
        $this->assertSame(60, strlen($row->token));
    }

    public function test_a_token_resets_the_password_and_is_then_spent(): void
    {
        $user = $this->user();

        $this->postJson('/password/forgot', ['email' => 'jo@example.test']);
        $token = DB::table('password_resets')->first()->token;

        $this->postJson('/password/reset', [
            'code' => $token,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])
            ->assertOk()
            ->assertExactJson(['error' => '']);

        $this->assertTrue(
            Hash::check('a-brand-new-password', $user->fresh()->password)
        );
        $this->assertSame(0, DB::table('password_resets')->count());
    }

    public function test_an_unknown_token_is_refused(): void
    {
        $this->postJson('/password/reset', [
            'code' => 'not-a-real-token',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertJsonPath(
            'error',
            'The token is no longer valid, please start the password request process again.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | reset_url_builder
    |--------------------------------------------------------------------------
    */

    /**
     * The default link build, read back through a builder that records what the
     * default would have produced. Asserting it here pins the shape an existing
     * consumer's emails already carry.
     */
    public function test_the_default_link_uses_app_url_and_a_path_segment(): void
    {
        $this->user();

        $captured = null;

        config()->set(
            'visns-packages.auth.reset_mail_factory',
            function ($content, $subject) use (&$captured) {
                $captured = $content;

                return null;
            }
        );

        $this->postJson('/password/forgot', ['email' => 'jo@example.test']);

        $token = DB::table('password_resets')->first()->token;

        $this->assertStringContainsString(
            'https://crm.example.test/verify/' . $token,
            $captured
        );
    }

    public function test_the_frontend_flag_switches_the_link_host(): void
    {
        $this->user();

        $captured = null;

        config()->set(
            'visns-packages.auth.reset_mail_factory',
            function ($content, $subject) use (&$captured) {
                $captured = $content;

                return null;
            }
        );

        $this->postJson('/password/forgot', [
            'email' => 'jo@example.test',
            'frontend' => 'true',
        ]);

        $this->assertStringContainsString('https://app.example.test/verify/', $captured);
    }

    public function test_a_custom_url_builder_owns_the_whole_link(): void
    {
        config()->set(
            'visns-packages.auth.reset_url_builder',
            PortalResetUrlBuilder::class
        );

        $this->user();

        $captured = null;

        config()->set(
            'visns-packages.auth.reset_mail_factory',
            function ($content, $subject) use (&$captured) {
                $captured = $content;

                return null;
            }
        );

        // Path form.
        $this->postJson('/password/forgot', ['email' => 'jo@example.test']);
        $token = DB::table('password_resets')->first()->token;

        $this->assertStringContainsString(
            'https://portal.example.test/verify/' . $token,
            $captured
        );

        DB::table('password_resets')->delete();

        // Query-string form, chosen by the application's own flag.
        $this->postJson('/password/forgot', [
            'email' => 'jo@example.test',
            'portal' => 'true',
        ]);
        $token = DB::table('password_resets')->first()->token;

        $this->assertStringContainsString(
            'https://portal.example.test/verify/?code=' . $token,
            $captured
        );
    }

    /*
    |--------------------------------------------------------------------------
    | reset_user_resolver + reset_key_by_resolved_email
    |--------------------------------------------------------------------------
    */

    public function test_a_resolver_finds_an_account_by_an_address_that_is_not_its_login(): void
    {
        config()->set(
            'visns-packages.auth.reset_user_resolver',
            ContactEmailResetResolver::class
        );

        $this->user();

        $this->postJson('/password/forgot', [
            'email' => 'contact+jbloggs@example.test',
        ])
            ->assertOk()
            ->assertJsonPath('error', '');

        $this->assertSame(1, DB::table('password_resets')->count());
    }

    public function test_the_row_is_keyed_on_the_typed_address_by_default(): void
    {
        config()->set(
            'visns-packages.auth.reset_user_resolver',
            ContactEmailResetResolver::class
        );

        $this->user();

        $this->postJson('/password/forgot', [
            'email' => 'contact+jbloggs@example.test',
        ]);

        $this->assertSame(
            'contact+jbloggs@example.test',
            DB::table('password_resets')->first()->email
        );
    }

    public function test_keying_on_the_resolved_address_makes_the_token_usable(): void
    {
        config()->set(
            'visns-packages.auth.reset_user_resolver',
            ContactEmailResetResolver::class
        );
        config()->set('visns-packages.auth.reset_key_by_resolved_email', true);

        $user = $this->user();

        $this->postJson('/password/forgot', [
            'email' => 'contact+jbloggs@example.test',
        ]);

        $row = DB::table('password_resets')->first();

        $this->assertSame('jo@example.test', $row->email);

        $this->postJson('/password/reset', [
            'code' => $row->token,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertJsonPath('error', '');

        $this->assertTrue(
            Hash::check('a-brand-new-password', $user->fresh()->password)
        );
    }

    public function test_a_token_row_whose_address_no_longer_resolves_is_refused_not_fatal(): void
    {
        $this->user();

        $this->postJson('/password/forgot', ['email' => 'jo@example.test']);
        $token = DB::table('password_resets')->first()->token;

        // The account moves on - a rename, a merge, a resolver that has since
        // narrowed. This used to dereference null and 500.
        User::query()->update(['email' => 'somewhere-else@example.test']);

        $this->postJson('/password/reset', [
            'code' => $token,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])
            ->assertOk()
            ->assertJsonPath(
                'error',
                'The token is no longer valid, please start the password request process again.'
            );
    }

    public function test_the_spent_token_is_deleted_by_the_rows_own_address(): void
    {
        config()->set(
            'visns-packages.auth.reset_user_resolver',
            ContactEmailResetResolver::class
        );

        $this->user();

        // Deliberately the mismatched combination: a resolver, but rows keyed on
        // the typed address. Deleting by the ACCOUNT's address would leave the
        // spent token alive and reusable.
        $this->postJson('/password/forgot', [
            'email' => 'contact+jbloggs@example.test',
        ]);

        $row = DB::table('password_resets')->first();

        $this->postJson('/password/reset', [
            'code' => $row->token,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertJsonPath('error', '');

        $this->assertSame(0, DB::table('password_resets')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | after_reset_hooks
    |--------------------------------------------------------------------------
    */

    public function test_after_reset_hooks_receive_the_account_and_the_new_password(): void
    {
        config()->set('visns-packages.auth.after_reset_hooks', [
            MirrorPasswordHook::class,
        ]);

        $user = $this->user();

        $this->postJson('/password/forgot', ['email' => 'jo@example.test']);
        $token = DB::table('password_resets')->first()->token;

        $this->postJson('/password/reset', [
            'code' => $token,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertJsonPath('error', '');

        $this->assertSame(
            [['user_id' => $user->id, 'password' => 'a-brand-new-password']],
            MirrorPasswordHook::$calls
        );
    }

    public function test_after_reset_hooks_do_not_fire_on_a_bad_token(): void
    {
        config()->set('visns-packages.auth.after_reset_hooks', [
            MirrorPasswordHook::class,
        ]);

        $this->postJson('/password/reset', [
            'code' => 'not-a-real-token',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->assertSame([], MirrorPasswordHook::$calls);
    }

    public function test_the_shipped_reset_defaults_are_all_inert(): void
    {
        $shipped = require __DIR__ . '/../../../config/visns-packages.php';

        $this->assertNull($shipped['auth']['reset_user_resolver']);
        $this->assertNull($shipped['auth']['reset_url_builder']);
        $this->assertFalse($shipped['auth']['reset_key_by_resolved_email']);
        $this->assertSame([], $shipped['auth']['after_reset_hooks']);
    }
}
