<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Impersonation;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Visnsstudio\VisnsPackages\Models\ImpersonationLog;
use Visnsstudio\VisnsPackages\Support\ImpersonationActor;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * Staff impersonation: issuing a token, and the unauthenticated endpoint that
 * exchanges it for the client it belongs to.
 *
 * Most of these tests are about what the module REFUSES. The validate endpoint
 * takes a token out of a URL and answers without any other credential, so every
 * rejection path is load-bearing.
 */
class ImpersonationModuleTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        // Routes are registered at boot.
        $app['config']->set('visns-packages.impersonation.enabled', true);
        // The fixture user model defines no relations, and the shipped defaults
        // name the CRM's; an adopting application sets its own.
        $app['config']->set('visns-packages.impersonation.user_relations', []);
        $app['config']->set('portal.url', 'https://portal.example.test');

        // Issuing is permission-gated in real life; here the permission is
        // exercised in its own test and stood down for the rest.
        $app['config']->set('visns-packages.impersonation.issue_middleware', [
            'web',
            'auth',
        ]);
    }

    protected function defineDatabaseMigrations()
    {
        parent::defineDatabaseMigrations();

        $this->runPackageMigration(
            '2026_08_19_200000_create_impersonation_log_table.php'
        );
    }

    private function staff(): User
    {
        return User::create([
            'firstname' => 'Sam',
            'surname' => 'Staff',
            'email' => 'sam@example.test',
            'password' => Hash::make('x'),
        ]);
    }

    private function client(): User
    {
        return User::create([
            'firstname' => 'Cleo',
            'surname' => 'Client',
            'email' => 'cleo@example.test',
            'company_contact_id' => 4242,
            'password' => Hash::make('x'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Issuing
    |--------------------------------------------------------------------------
    */

    public function test_issuing_returns_a_portal_url_carrying_the_token(): void
    {
        $staff = $this->staff();
        $client = $this->client();

        $response = $this->actingAs($staff)
            ->postJson('/ajax/impersonateClient', ['id' => 4242])
            ->assertOk();

        $url = $response->json('url');

        $this->assertStringStartsWith(
            'https://portal.example.test/portal/impersonate?token=',
            $url
        );

        $token = substr($url, strpos($url, 'token=') + 6);

        $this->assertNotNull(PersonalAccessToken::findToken($token));
        $this->assertSame(
            'impersonation-token:' . $staff->id,
            $client->tokens()->first()->name
        );
    }

    public function test_the_portal_path_is_not_doubled_when_the_url_already_carries_it(): void
    {
        config()->set('portal.url', 'https://portal.example.test/portal');

        $this->client();

        $url = $this->actingAs($this->staff())
            ->postJson('/ajax/impersonateClient', ['id' => 4242])
            ->json('url');

        $this->assertStringStartsWith(
            'https://portal.example.test/portal/impersonate?token=',
            $url
        );
    }

    public function test_the_token_expires(): void
    {
        config()->set('visns-packages.impersonation.expires_minutes', 60);

        $client = $this->client();

        $this->actingAs($this->staff())
            ->postJson('/ajax/impersonateClient', ['id' => 4242])
            ->assertOk();

        $expires = $client->tokens()->first()->expires_at;

        $this->assertNotNull($expires);
        $this->assertEqualsWithDelta(
            60,
            now()->diffInMinutes($expires),
            1
        );
    }

    public function test_issuing_revokes_prior_impersonation_tokens_but_not_the_clients_own(): void
    {
        $client = $this->client();

        $client->createToken('portal-token');
        $client->createToken('impersonation-token:99');

        $this->actingAs($this->staff())
            ->postJson('/ajax/impersonateClient', ['id' => 4242])
            ->assertOk();

        $names = $client->tokens()->pluck('name')->sort()->values()->all();

        // Revoking the client's own login token would sign them out of their
        // portal every time a staff member looked at their account.
        $this->assertContains('portal-token', $names);
        $this->assertNotContains('impersonation-token:99', $names);
        $this->assertCount(2, $names);
    }

    public function test_a_client_without_a_portal_account_is_404_on_the_message_key(): void
    {
        $this->actingAs($this->staff())
            ->postJson('/ajax/impersonateClient', ['id' => 999999])
            ->assertStatus(404)
            // `message`, not `error` - the shape the CRM front end reads.
            ->assertExactJson([
                'message' => 'This client does not have a portal account yet. Please set a username and password for the client before accessing the portal.',
            ]);
    }

    public function test_an_audit_row_is_written_without_the_token(): void
    {
        $staff = $this->staff();
        $client = $this->client();

        $this->actingAs($staff)
            ->postJson('/ajax/impersonateClient', ['id' => 4242])
            ->assertOk();

        $log = ImpersonationLog::first();

        $this->assertNotNull($log);
        $this->assertSame($staff->id, $log->staff_user_id);
        $this->assertSame($client->id, $log->client_user_id);
        $this->assertSame(4242, (int) $log->company_contact_id);
        $this->assertNotNull($log->token_expires_at);

        // The row records the act; it must never be a way to replay it.
        $this->assertStringNotContainsString(
            'token',
            implode(' ', array_keys(array_filter(
                $log->getAttributes(),
                fn($value, $key) => $key !== 'token_expires_at',
                ARRAY_FILTER_USE_BOTH
            )))
        );
    }

    public function test_audit_logging_can_be_switched_off(): void
    {
        config()->set('visns-packages.impersonation.log_model', false);

        $this->client();

        $this->actingAs($this->staff())
            ->postJson('/ajax/impersonateClient', ['id' => 4242])
            ->assertOk();

        $this->assertSame(0, ImpersonationLog::count());
    }

    public function test_issuing_requires_authentication(): void
    {
        $this->client();

        $this->postJson('/ajax/impersonateClient', ['id' => 4242])
            ->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | Validating
    |--------------------------------------------------------------------------
    */

    private function issuedToken(): string
    {
        $this->client();

        $url = $this->actingAs($this->staff())
            ->postJson('/ajax/impersonateClient', ['id' => 4242])
            ->json('url');

        return substr($url, strpos($url, 'token=') + 6);
    }

    public function test_a_valid_token_returns_the_whitelisted_payload_only(): void
    {
        $token = $this->issuedToken();

        $response = $this->postJson('/api/validateImpersonationToken', [
            'token' => $token,
        ])->assertOk();

        $response
            ->assertJsonPath('error', '')
            ->assertJsonPath('full_data_available', true)
            ->assertJsonPath('user.firstname', 'Cleo')
            ->assertJsonPath('user.company_contact_id', 4242);

        // Whitelist, not blacklist: the endpoint is unauthenticated and the
        // token travels in a URL, so anything not named here must not appear.
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
    }

    public function test_an_unknown_token_is_401(): void
    {
        $this->postJson('/api/validateImpersonationToken', [
            'token' => '1|totallymadeup',
        ])
            ->assertStatus(401)
            ->assertExactJson(['error' => 'Invalid token']);
    }

    public function test_a_token_id_without_the_secret_is_401(): void
    {
        $token = $this->issuedToken();
        $id = explode('|', $token)[0];

        // findToken() verifies the plaintext against the stored hash. Looking a
        // token up by its id alone would let anyone enumerate ids on this
        // unauthenticated endpoint and pull back user data.
        $this->postJson('/api/validateImpersonationToken', ['token' => $id])
            ->assertStatus(401);
    }

    public function test_a_non_impersonation_token_cannot_be_laundered_through(): void
    {
        $client = $this->client();
        $plain = $client->createToken('portal-token')->plainTextToken;

        $this->postJson('/api/validateImpersonationToken', ['token' => $plain])
            ->assertStatus(401)
            ->assertExactJson(['error' => 'Invalid token']);
    }

    public function test_an_expired_token_is_401_with_its_own_message(): void
    {
        $token = $this->issuedToken();

        PersonalAccessToken::query()->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/validateImpersonationToken', ['token' => $token])
            ->assertStatus(401)
            ->assertExactJson(['error' => 'Token has expired']);
    }

    /*
    |--------------------------------------------------------------------------
    | Actor attribution
    |--------------------------------------------------------------------------
    */

    public function test_the_acting_staff_id_is_recoverable_from_the_token(): void
    {
        $staff = $this->staff();
        $client = $this->client();

        $this->actingAs($staff)
            ->postJson('/ajax/impersonateClient', ['id' => 4242])
            ->assertOk();

        // Auth::user() during an impersonated request is the CLIENT, so the
        // token name is the only place the real human survives.
        $client->withAccessToken($client->tokens()->first());
        $this->be($client);

        $this->assertSame($staff->id, ImpersonationActor::id());
        $this->assertTrue(ImpersonationActor::isImpersonating());
    }

    public function test_an_ordinary_token_reports_no_actor(): void
    {
        $client = $this->client();
        $client->createToken('portal-token');

        $client->withAccessToken($client->tokens()->first());
        $this->be($client);

        $this->assertNull(ImpersonationActor::id());
        $this->assertFalse(ImpersonationActor::isImpersonating());
    }

    public function test_the_module_ships_disabled(): void
    {
        $shipped = require __DIR__ . '/../../../config/visns-packages.php';

        $this->assertFalse($shipped['impersonation']['enabled']);
    }
}
