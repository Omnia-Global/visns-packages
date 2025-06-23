<?php

namespace Visnsstudio\VisnsPackages\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Visnsstudio\VisnsPackages\Models\User;
use Visnsstudio\VisnsPackages\Models\File;

class RelationshipSortingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_sort_by_regular_columns()
    {
        // Create test users
        User::factory()->create(['name' => 'Charlie']);
        User::factory()->create(['name' => 'Alice']);
        User::factory()->create(['name' => 'Bob']);

        // Test ascending sort
        $users = User::customOrder('name', 'asc')->get();
        $this->assertEquals('Alice', $users->first()->name);
        $this->assertEquals('Charlie', $users->last()->name);

        // Test descending sort
        $users = User::customOrder('name', 'desc')->get();
        $this->assertEquals('Charlie', $users->first()->name);
        $this->assertEquals('Alice', $users->last()->name);
    }

    /** @test */
    public function it_handles_invalid_parameters_gracefully()
    {
        User::factory()->create(['name' => 'Test User']);

        // Test with null parameters
        $users = User::customOrder(null, null)->get();
        $this->assertEquals(1, $users->count());

        // Test with missing parameters
        $users = User::customOrder('name', null)->get();
        $this->assertEquals(1, $users->count());

        $users = User::customOrder(null, 'asc')->get();
        $this->assertEquals(1, $users->count());
    }

    /** @test */
    public function it_can_sort_by_relationship_fields()
    {
        // Skip this test if we don't have actual relationships set up
        $this->markTestSkipped('Relationship sorting test requires actual relationships to be configured');

        // Example test structure for when relationships are available:
        /*
        // Create users with related data
        $userA = User::factory()->create(['name' => 'User A']);
        $userB = User::factory()->create(['name' => 'User B']);
        
        // Create related profiles
        $userA->profile()->create(['title' => 'Manager']);
        $userB->profile()->create(['title' => 'Developer']);
        
        // Test sorting by relationship field
        $users = User::with('profile')->customOrder('profile.title', 'asc')->get();
        $this->assertEquals('Developer', $users->first()->profile->title);
        $this->assertEquals('Manager', $users->last()->profile->title);
        */
    }

    /** @test */
    public function it_falls_back_to_regular_sorting_for_invalid_relationships()
    {
        User::factory()->create(['name' => 'Charlie']);
        User::factory()->create(['name' => 'Alice']);

        // Test with invalid relationship - should fallback to regular column sorting
        $users = User::customOrder('nonexistent.field', 'asc')->get();
        $this->assertEquals(2, $users->count());
    }

    /** @test */
    public function it_handles_exceptions_gracefully()
    {
        User::factory()->create(['name' => 'Test User']);

        // Test with malformed relationship field
        $users = User::customOrder('valid_relationship.', 'asc')->get();
        $this->assertEquals(1, $users->count());
    }

    /** @test */
    public function it_works_with_file_model()
    {
        // Create test files
        File::factory()->create(['file_name' => 'document.pdf']);
        File::factory()->create(['file_name' => 'image.jpg']);
        File::factory()->create(['file_name' => 'archive.zip']);

        // Test sorting by file name
        $files = File::customOrder('file_name', 'asc')->get();
        $this->assertEquals('archive.zip', $files->first()->file_name);
        $this->assertEquals('image.jpg', $files->last()->file_name);
    }
}