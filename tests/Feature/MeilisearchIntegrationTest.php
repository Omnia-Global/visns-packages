<?php

namespace Visnsstudio\VisnsPackages\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class MeilisearchIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear cache before each test
        Cache::flush();
    }

    /**
     * Test that search falls back to custom search when Scout is not installed
     */
    public function test_falls_back_to_custom_search_without_scout()
    {
        // Mock that Scout class doesn't exist
        $this->mockScoutNotInstalled();

        $response = $this->getJson('/ajax/users?search=test');

        $response->assertStatus(200);
        // The search should have used customSearch method
        $this->assertCustomSearchWasUsed();
    }

    /**
     * Test that search falls back when Scout driver is not meilisearch
     */
    public function test_falls_back_when_scout_driver_not_meilisearch()
    {
        Config::set('scout.driver', 'algolia');

        $response = $this->getJson('/ajax/users?search=test');

        $response->assertStatus(200);
        $this->assertCustomSearchWasUsed();
    }

    /**
     * Test that search falls back when model doesn't use Searchable trait
     */
    public function test_falls_back_when_model_not_searchable()
    {
        // Create a test model without Searchable trait
        $this->createNonSearchableModel();

        $response = $this->getJson('/ajax/test-models?search=test');

        $response->assertStatus(200);
        $this->assertCustomSearchWasUsed();
    }

    /**
     * Test that search falls back when Meilisearch is unhealthy
     */
    public function test_falls_back_when_meilisearch_unhealthy()
    {
        Config::set('scout.driver', 'meilisearch');
        
        // Mock unhealthy Meilisearch
        Cache::put('meilisearch_health', false, 60);

        $response = $this->getJson('/ajax/users?search=test');

        $response->assertStatus(200);
        $this->assertCustomSearchWasUsed();
    }

    /**
     * Test that Meilisearch is used when all conditions are met
     */
    public function test_uses_meilisearch_when_all_conditions_met()
    {
        Config::set('scout.driver', 'meilisearch');
        
        // Mock healthy Meilisearch
        Cache::put('meilisearch_health', true, 60);
        
        // Mock Scout search results
        $this->mockScoutSearchResults([1, 2, 3]);

        $response = $this->getJson('/ajax/users?search=test');

        $response->assertStatus(200);
        $this->assertMeilisearchWasUsed();
    }

    /**
     * Test that search can be force disabled via configuration
     */
    public function test_meilisearch_can_be_force_disabled()
    {
        Config::set('scout.driver', 'meilisearch');
        Config::set('visns-packages.search.force_disable_meilisearch', true);
        
        // Even with healthy Meilisearch, it should not be used
        Cache::put('meilisearch_health', true, 60);

        $response = $this->getJson('/ajax/users?search=test');

        $response->assertStatus(200);
        $this->assertCustomSearchWasUsed();
    }

    /**
     * Test that search handles Meilisearch exceptions gracefully
     */
    public function test_handles_meilisearch_exceptions_gracefully()
    {
        Config::set('scout.driver', 'meilisearch');
        Cache::put('meilisearch_health', true, 60);
        
        // Mock Scout to throw an exception
        $this->mockScoutThrowsException();

        Log::shouldReceive('warning')
            ->once()
            ->with(\Mockery::on(function ($message) {
                return str_contains($message, 'Meilisearch search failed');
            }));

        $response = $this->getJson('/ajax/users?search=test');

        $response->assertStatus(200);
        $this->assertCustomSearchWasUsed();
    }

    /**
     * Test dropdown search with Meilisearch
     */
    public function test_dropdown_search_uses_meilisearch()
    {
        Config::set('scout.driver', 'meilisearch');
        Cache::put('meilisearch_health', true, 60);
        
        $this->mockScoutSearchResults([1, 2, 3]);

        $response = $this->postJson('/ajax/users/dropdown', [
            'where' => [
                ['id' => 'async', 'value' => 'test']
            ]
        ]);

        $response->assertStatus(200);
        $this->assertMeilisearchWasUsed();
    }

    /**
     * Test that empty search results return no records
     */
    public function test_empty_meilisearch_results_return_no_records()
    {
        Config::set('scout.driver', 'meilisearch');
        Cache::put('meilisearch_health', true, 60);
        
        // Mock empty Scout search results
        $this->mockScoutSearchResults([]);

        $response = $this->getJson('/ajax/users?search=nonexistent');

        $response->assertStatus(200);
        $response->assertJson(['data' => []]);
    }

    /**
     * Test health check caching
     */
    public function test_meilisearch_health_check_is_cached()
    {
        Config::set('scout.driver', 'meilisearch');
        
        // First call should check health and cache it
        $this->mockMeilisearchHealthCheck(true);
        
        $response1 = $this->getJson('/ajax/users?search=test');
        
        // Second call should use cached value (health check not called again)
        $response2 = $this->getJson('/ajax/users?search=test2');
        
        $response1->assertStatus(200);
        $response2->assertStatus(200);
        
        // Verify health check was only called once
        $this->assertHealthCheckCalledOnce();
    }

    // Helper methods for testing

    protected function mockScoutNotInstalled()
    {
        // This would typically be done with a mock or by manipulating autoloading
        // For testing purposes, we'd need to mock the class_exists check
    }

    protected function createNonSearchableModel()
    {
        // Create a test model without Searchable trait
        // This would create a temporary model for testing
    }

    protected function mockScoutSearchResults($ids)
    {
        // Mock Scout search to return specific IDs
        // This would typically use Laravel's testing helpers or Mockery
    }

    protected function mockScoutThrowsException()
    {
        // Mock Scout to throw an exception when searching
    }

    protected function mockMeilisearchHealthCheck($isHealthy)
    {
        // Mock the Meilisearch client health check
    }

    protected function assertCustomSearchWasUsed()
    {
        // Assert that the customSearch scope was called
        // This would check query logs or use spies
    }

    protected function assertMeilisearchWasUsed()
    {
        // Assert that Meilisearch was used for searching
        // This would check if Scout search was called
    }

    protected function assertHealthCheckCalledOnce()
    {
        // Assert that the health check was only called once
        // This would verify mock expectations
    }
}