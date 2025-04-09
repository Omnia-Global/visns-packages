<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class DynamicControllerOrKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test table
        if (!Schema::hasTable('test_items')) {
            Schema::create('test_items', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->nullable();
                $table->timestamps();
            });

            // Insert some test data
            DB::table('test_items')->insert([
                ['name' => 'Item 1', 'code' => 'CODE1'],
                ['name' => 'Item 2', 'code' => 'CODE2'],
                ['name' => 'Item 3', 'code' => 'CODE3'],
                ['name' => 'Item 4', 'code' => null],
            ]);
        }

        // Create a test model if it doesn't exist
        if (!class_exists('App\Models\TestItem')) {
            file_put_contents(
                app_path('Models/TestItem.php'),
                '<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        "name",
        "code",
    ];

    public function validationRules($action, $data = [])
    {
        return [
            "name" => ["required", "string"],
            "code" => ["nullable", "string"],
        ];
    }

    public function loadableRelations()
    {
        return [];
    }
}'
            );
        }
    }

    /** @test */
    public function it_can_filter_with_or_key()
    {
        // Create a user for authentication
        $user = User::factory()->create();
        $this->actingAs($user);

        // Test the regular where condition
        $response = $this->getJson(
            '/ajax/test_items/table?where[0][id]=name&where[0][value]=Item 1'
        );
        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('total'));

        // Test with orKey condition
        $response = $this->getJson(
            '/ajax/test_items/table?where[0][id]=name&where[0][value]=Item 1&where[0][orKey]=code'
        );
        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('total')); // Should match Item 1 and CODE1
    }
}
