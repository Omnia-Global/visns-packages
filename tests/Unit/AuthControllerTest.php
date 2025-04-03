<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;
use App\Models\User;
use Visnsstudio\VisnsPackages\Controllers\AuthController;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $authController;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authController = new AuthController();
    }

    /** @test */
    public function it_authenticates_user_without_2fa()
    {
        // Create a user without 2FA
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => null,
        ]);

        // Create a request with credentials
        $request = Request::create('/login', 'POST', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        // Mock the session
        $request->setLaravelSession(Session::driver());

        // Call the authenticate method
        $response = $this->authController->authenticate($request);

        // Assert the response contains the user and no error
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertEmpty($responseData['error']);
        $this->assertFalse($responseData['requires_two_factor']);
    }

    /** @test */
    public function it_requires_2fa_when_enabled()
    {
        // Create a user with 2FA enabled
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => 'secret',
        ]);

        // Create a request with credentials
        $request = Request::create('/login', 'POST', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        // Mock the session
        $request->setLaravelSession(Session::driver());

        // Call the authenticate method
        $response = $this->authController->authenticate($request);

        // Assert the response indicates 2FA is required
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['requires_two_factor']);
        $this->assertEmpty($responseData['error']);
        
        // Assert the session contains the user ID for 2FA challenge
        $this->assertEquals($user->id, session('auth.two_factor.user_id'));
    }

    /** @test */
    public function it_validates_2fa_code()
    {
        // This test would require mocking the TwoFactorAuthenticatable trait methods
        // which is beyond the scope of this example
        $this->markTestSkipped('This test requires mocking the TwoFactorAuthenticatable trait methods');
    }

    /** @test */
    public function it_handles_api_login_without_2fa()
    {
        // Create a user without 2FA
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => null,
        ]);

        // Create a request with credentials
        $request = Request::create('/api/login', 'POST', [
            'username' => 'test@example.com',
            'password' => 'password',
        ]);

        // Call the login_api method
        $response = $this->authController->login_api($request);

        // Assert the response contains a token
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('id', $responseData);
    }

    /** @test */
    public function it_requires_2fa_for_api_login_when_enabled()
    {
        // Create a user with 2FA enabled
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'two_factor_secret' => 'secret',
        ]);

        // Create a request with credentials
        $request = Request::create('/api/login', 'POST', [
            'username' => 'test@example.com',
            'password' => 'password',
        ]);

        // Call the login_api method
        $response = $this->authController->login_api($request);

        // Assert the response indicates 2FA is required
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['two_factor_required']);
        $this->assertEquals($user->id, $responseData['user_id']);
    }
}
