<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\RecordingGate;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\RecordingHook;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\RejectingGate;
use Visnsstudio\VisnsPackages\Tests\Fixtures\Auth\UppercaseEmailResolver;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * The upgrade contract: with nothing configured, the login endpoints answer
 * exactly what they answered before this batch. Every assertion here is a
 * literal from the pre-existing controller.
 */
class AuthBackwardCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingGate::$calls = [];
        RecordingHook::$calls = [];
    }

    private function user(array $attributes = []): User
    {
        return User::create(array_merge([
            'firstname' => 'Jo',
            'surname' => 'Bloggs',
            'username' => 'jbloggs',
            'email' => 'jo@example.test',
            'password' => Hash::make('correct-horse'),
        ], $attributes));
    }

    public function test_authenticate_returns_the_historical_envelope_on_success(): void
    {
        $user = $this->user();

        $response = $this->postJson('/login/authenticate', [
            'email' => 'jo@example.test',
            'password' => 'correct-horse',
            'location' => '/clients/42',
        ]);

        $response->assertOk()
            ->assertJsonPath('error', '')
            ->assertJsonPath('previous', '/clients/42')
            ->assertJsonPath('requires_two_factor', false)
            ->assertJsonPath('user.id', $user->id);

        $this->assertAuthenticatedAs(User::find($user->id));
    }

    public function test_a_location_of_login_or_root_is_blanked_by_default(): void
    {
        $this->user();

        foreach (['/', '/login'] as $location) {
            $this->postJson('/login/authenticate', [
                'email' => 'jo@example.test',
                'password' => 'correct-horse',
                'location' => $location,
            ])->assertJsonPath('previous', '');
        }
    }

    public function test_previous_filtering_can_be_switched_off(): void
    {
        config()->set('visns-packages.auth.filter_previous', false);

        $this->user();

        $this->postJson('/login/authenticate', [
            'email' => 'jo@example.test',
            'password' => 'correct-horse',
            'location' => '/login',
        ])->assertJsonPath('previous', '/login');
    }

    public function test_a_bad_password_returns_the_historical_failure_shape(): void
    {
        $this->user();

        $this->postJson('/login/authenticate', [
            'email' => 'jo@example.test',
            'password' => 'wrong',
        ])
            ->assertOk()
            ->assertJsonPath('error', 'Login unsuccessful, please try again.')
            // '' rather than null: the front end distinguishes the two.
            ->assertJsonPath('user', '')
            ->assertJsonPath('requires_two_factor', false);

        $this->assertGuest();
    }

    public function test_a_disabled_account_is_refused(): void
    {
        $this->user(['disabled' => true]);

        $this->postJson('/login/authenticate', [
            'email' => 'jo@example.test',
            'password' => 'correct-horse',
        ])->assertJsonPath(
            'error',
            'Your account has been disabled. Please contact the administrator.'
        );

        $this->assertGuest();
    }

    public function test_a_username_is_accepted_where_the_input_is_not_an_email(): void
    {
        $this->user();

        $this->postJson('/login/authenticate', [
            'email' => 'jbloggs',
            'password' => 'correct-horse',
        ])->assertJsonPath('error', '');
    }

    public function test_messages_are_configurable(): void
    {
        config()->set(
            'visns-packages.auth.messages.login_failed',
            'Nope. Try again.'
        );

        $this->user();

        $this->postJson('/login/authenticate', [
            'email' => 'jo@example.test',
            'password' => 'wrong',
        ])->assertJsonPath('error', 'Nope. Try again.');
    }

    public function test_the_api_login_returns_a_token(): void
    {
        $this->user();

        $response = $this->postJson('/api/login', [
            'username' => 'jo@example.test',
            'password' => 'correct-horse',
        ])->assertOk();

        $this->assertNotEmpty($response->json('id'));
    }

    public function test_the_api_login_rejects_bad_credentials_with_401(): void
    {
        $this->user();

        $this->postJson('/api/login', [
            'username' => 'jo@example.test',
            'password' => 'wrong',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error', 'Unauthenticated');
    }

    /*
    |--------------------------------------------------------------------------
    | Extension points
    |--------------------------------------------------------------------------
    */

    public function test_a_custom_user_resolver_replaces_the_built_in_lookup(): void
    {
        config()->set(
            'visns-packages.auth.user_resolver',
            UppercaseEmailResolver::class
        );

        $this->user(['email' => 'JO@EXAMPLE.TEST']);

        // The built-in resolver would miss on case; the custom one upper-cases
        // before looking up.
        $this->postJson('/login/authenticate', [
            'email' => 'jo@example.test',
            'password' => 'correct-horse',
        ])->assertJsonPath('error', '');
    }

    public function test_a_pre_login_gate_can_refuse_the_login_with_its_own_response(): void
    {
        config()->set('visns-packages.auth.pre_login_gates', [RejectingGate::class]);

        $this->user();

        $this->postJson('/login/authenticate', [
            'email' => 'jo@example.test',
            'password' => 'correct-horse',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'Your account is currently inactive.');

        $this->assertGuest();
    }

    public function test_gates_run_after_the_password_check_by_default(): void
    {
        config()->set('visns-packages.auth.pre_login_gates', [RecordingGate::class]);

        $this->user();

        $this->postJson('/login/authenticate', [
            'email' => 'jo@example.test',
            'password' => 'wrong',
        ]);

        // A gate that ran before the password check would let a caller probe
        // which accounts exist by watching for a gate-shaped refusal.
        $this->assertSame([], RecordingGate::$calls);
    }

    public function test_gates_can_be_moved_ahead_of_the_password_check(): void
    {
        config()->set('visns-packages.auth.pre_login_gates', [RecordingGate::class]);
        config()->set('visns-packages.auth.run_gates_before_credential_check', true);

        $user = $this->user();

        $this->postJson('/login/authenticate', [
            'email' => 'jo@example.test',
            'password' => 'wrong',
        ]);

        $this->assertSame([$user->id], RecordingGate::$calls);
    }

    public function test_post_login_hooks_fire_on_a_successful_login(): void
    {
        config()->set('visns-packages.auth.post_login_hooks', [RecordingHook::class]);

        $user = $this->user();

        $this->postJson('/login/authenticate', [
            'email' => 'jo@example.test',
            'password' => 'correct-horse',
        ])->assertJsonPath('error', '');

        $this->assertSame([$user->id], RecordingHook::$calls);
    }

    public function test_post_login_hooks_do_not_fire_on_a_failed_login(): void
    {
        config()->set('visns-packages.auth.post_login_hooks', [RecordingHook::class]);

        $this->user();

        $this->postJson('/login/authenticate', [
            'email' => 'jo@example.test',
            'password' => 'wrong',
        ]);

        $this->assertSame([], RecordingHook::$calls);
    }

    /*
    |--------------------------------------------------------------------------
    | logout_api
    |--------------------------------------------------------------------------
    */

    /**
     * The package registers /api/logout without a guard, so `$request->user()`
     * resolves through whatever the application's default guard is. An
     * application that means to use this endpoint points that guard at Sanctum;
     * these tests do the same, otherwise the request is simply anonymous and
     * there is nothing to assert about.
     */
    private function useSanctumAsDefaultGuard(): void
    {
        config()->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
        ]);

        // The default guard, not the web guard: Sanctum's own guard falls back
        // to the web guard for stateful requests, so pointing web at Sanctum
        // makes it call itself.
        config()->set('auth.defaults.guard', 'sanctum');
    }

    public function test_logout_api_deletes_only_the_calling_token(): void
    {
        $this->useSanctumAsDefaultGuard();

        $user = $this->user();

        $keep = $user->createToken('other-device')->plainTextToken;
        $used = $user->createToken('this-device')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $used)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertExactJson(['message' => 'Successfully logged out']);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('other-device', $user->tokens()->first()->name);
        $this->assertNotEmpty($keep);
    }

    public function test_logout_api_survives_a_session_authenticated_request(): void
    {
        // A stateful SPA request yields a TransientToken, which has no delete().
        // Calling it used to fatal; the guard is the whole point of this test.
        $user = $this->user();

        $this->actingAs(User::find($user->id))
            ->postJson('/api/logout')
            ->assertOk();
    }

    public function test_the_logout_response_shape_is_configurable(): void
    {
        config()->set('visns-packages.auth.logout_response', ['error' => '']);

        $this->useSanctumAsDefaultGuard();

        $user = $this->user();
        $token = $user->createToken('this-device')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout')
            ->assertExactJson(['error' => '']);
    }
}
