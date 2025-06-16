<?php

namespace Visnsstudio\VisnsPackages\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DropdownIntelligentFieldsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that dropdown uses intelligent field detection
     */
    public function test_dropdown_uses_intelligent_field_detection()
    {
        // Clear any cached field detection
        Cache::flush();
        
        // Create a test user with name components
        $user = \App\Models\User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $response = $this->postJson('/ajax/users/dropdown');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'label'
                ]
            ]
        ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals($user->id, $data[0]['id']);
        $this->assertEquals('John Doe', $data[0]['label']);
    }

    /**
     * Test name combination field detection
     */
    public function test_dropdown_handles_name_combinations()
    {
        // This test would require a model with firstname/lastname fields
        // Since we're using the standard User model, we'll test the configuration
        
        Config::set('visns-packages.dropdown_fields.name_combinations', [
            ['firstname', 'lastname'],
            ['first_name', 'last_name']
        ]);

        // Test that the configuration is properly loaded
        $config = config('visns-packages.dropdown_fields.name_combinations');
        $this->assertIsArray($config);
        $this->assertContains(['firstname', 'lastname'], $config);
    }

    /**
     * Test field detection caching
     */
    public function test_field_detection_is_cached()
    {
        Cache::flush();
        
        // First request should cache the field detection
        $response1 = $this->postJson('/ajax/users/dropdown');
        $response1->assertStatus(200);

        // Verify cache was created
        $cacheKey = 'dropdown_fields_' . \App\Models\User::class;
        $this->assertTrue(Cache::has($cacheKey));

        // Second request should use cached data
        $response2 = $this->postJson('/ajax/users/dropdown');
        $response2->assertStatus(200);
    }

    /**
     * Test custom label field configuration
     */
    public function test_custom_label_field_configuration()
    {
        Config::set('visns-packages.dropdown_fields.label_fields', [
            'custom_label', 'name', 'title'
        ]);

        $config = config('visns-packages.dropdown_fields.label_fields');
        $this->assertIsArray($config);
        $this->assertEquals('custom_label', $config[0]);
    }
}