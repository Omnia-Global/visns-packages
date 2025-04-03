<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use App\Models\User;
use Visnsstudio\VisnsPackages\Controllers\UserController;

class UserControllerTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected $userController;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userController = new UserController();
    }

    /** @test */
    public function it_returns_two_factor_status_in_profile()
    {
        // Create a user with 2FA enabled
        $user = User::factory()->create([
            'two_factor_secret' => 'test-secret',
            'two_factor_confirmed_at' => now(),
        ]);

        // Log in as the user
        Auth::login($user);

        // Call the profile method
        $response = $this->userController->profile();

        // Assert the response contains the 2FA status
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('two_factor_enabled', $responseData);
        $this->assertTrue($responseData['two_factor_enabled']);
    }

    /** @test */
    public function it_returns_two_factor_status()
    {
        // Create a user with 2FA enabled
        $user = User::factory()->create([
            'two_factor_secret' => 'test-secret',
            'two_factor_confirmed_at' => now(),
        ]);

        // Log in as the user
        Auth::login($user);

        // Create a request
        $request = Request::create('/user/two-factor-auth', 'GET');

        // Call the getTwoFactorStatus method
        $response = $this->userController->getTwoFactorStatus($request);

        // Assert the response contains the correct 2FA status
        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['two_factor_supported']);
        $this->assertTrue($responseData['two_factor_enabled']);
        $this->assertTrue($responseData['two_factor_confirmed']);
    }

    /** @test */
    public function it_disables_two_factor_auth()
    {
        // Create a user with 2FA enabled
        $user = User::factory()->create([
            'password' => Hash::make('password'),
            'two_factor_secret' => 'test-secret',
            'two_factor_recovery_codes' => json_encode(['code1', 'code2']),
            'two_factor_confirmed_at' => now(),
        ]);

        // Log in as the user
        Auth::login($user);

        // Create a request with the password
        $request = Request::create('/user/two-factor-auth', 'DELETE', [
            'password' => 'password',
        ]);

        // Call the disableTwoFactorAuth method
        $response = $this->userController->disableTwoFactorAuth($request);

        // Assert the response indicates 2FA is disabled
        $responseData = json_decode($response->getContent(), true);
        $this->assertFalse($responseData['two_factor_enabled']);

        // Refresh the user from the database
        $user->refresh();

        // Assert the 2FA fields are cleared
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    /** @test */
    public function it_requires_correct_password_to_disable_two_factor_auth()
    {
        // Create a user with 2FA enabled
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
            'two_factor_secret' => 'test-secret',
            'two_factor_confirmed_at' => now(),
        ]);

        // Log in as the user
        Auth::login($user);

        // Create a request with an incorrect password
        $request = Request::create('/user/two-factor-auth', 'DELETE', [
            'password' => 'wrong-password',
        ]);

        // Expect an exception when calling the disableTwoFactorAuth method
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->userController->disableTwoFactorAuth($request);
    }

    /** @test */
    public function it_enables_two_factor_auth()
    {
        // This test would require mocking the TwoFactorAuthenticatable trait methods
        // which is beyond the scope of this example
        $this->markTestSkipped('This test requires mocking the TwoFactorAuthenticatable trait methods');
    }

    /** @test */
    public function it_confirms_two_factor_auth()
    {
        // This test would require mocking the TwoFactorAuthenticatable trait methods
        // which is beyond the scope of this example
        $this->markTestSkipped('This test requires mocking the TwoFactorAuthenticatable trait methods');
    }

    /** @test */
    public function it_regenerates_recovery_codes()
    {
        // This test would require mocking the TwoFactorAuthenticatable trait methods
        // which is beyond the scope of this example
        $this->markTestSkipped('This test requires mocking the TwoFactorAuthenticatable trait methods');
    }
}
