<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Security;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Visnsstudio\VisnsPackages\Services\OAuthManager;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * 4.17.3: the OAuth leg of an integration belongs to a signed-in user who may
 * manage integrations, and its state belongs to the user who started it.
 *
 * Before this the authorise and callback routes answered a guest and the state
 * was a cache key tied to nobody, so anybody could run the consent leg with an
 * account of their own and replace the organisation's connection.
 */
class OAuthRouteSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['visns-packages.integrations_permission' => 'manage integrations']);
        Gate::define('manage integrations', fn ($user) => str_starts_with($user->email, 'admin'));
    }

    private function user(string $email): User
    {
        return User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('secret-password')]);
    }

    private function state(OAuthManager $manager, string $method, ...$args)
    {
        $reflection = new \ReflectionMethod($manager, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($manager, ...$args);
    }

    #[Test]
    public function a_guest_cannot_start_or_finish_the_oauth_leg(): void
    {
        $this->getJson('/integrations/oauth/zoho/authorize')->assertStatus(401);
        $this->getJson('/integrations/oauth/zoho/callback?code=x&state=y')->assertStatus(401);
    }

    #[Test]
    public function a_signed_in_user_without_the_permission_is_refused(): void
    {
        $this->actingAs($this->user('staff@example.test'));

        $this->getJson('/integrations/oauth/zoho/authorize')->assertStatus(403);
        $this->getJson('/integrations/oauth/zoho/callback?code=x&state=y')->assertStatus(403);
        $this->postJson('/integrations/oauth/zoho/disconnect')->assertStatus(403);
        $this->postJson('/integrations/oauth/zoho/sync')->assertStatus(403);
    }

    #[Test]
    public function the_state_is_accepted_only_from_the_user_who_started_the_flow_and_only_once(): void
    {
        $manager = app(OAuthManager::class);
        $admin = $this->user('admin@example.test');
        $other = $this->user('admin-two@example.test');

        $this->actingAs($admin);
        $state = $this->state($manager, 'generateState', 'zoho');

        $this->actingAs($other);
        $this->assertFalse($this->state($manager, 'validateState', $state, 'zoho'), 'another user must not redeem it');

        $this->actingAs($admin);
        $this->assertFalse($this->state($manager, 'validateState', $state, 'zoho'), 'a presented state is spent');

        $fresh = $this->state($manager, 'generateState', 'zoho');
        $this->assertFalse($this->state($manager, 'validateState', $fresh, 'zoom_sms'), 'the provider must match');

        $fresh = $this->state($manager, 'generateState', 'zoho');
        $this->assertTrue($this->state($manager, 'validateState', $fresh, 'zoho'));
    }
}
