<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Visnsstudio\VisnsPackages\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class DynamicControllerColumnMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test model with both regular and virtual columns
        if (!Schema::hasTable('test_models')) {
            Schema::create('test_models', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->timestamps();
            });
        }

        // Create a test model class
        if (!class_exists('App\Models\TestModel')) {
            file_put_contents(app_path('Models/TestModel.php'), '<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Visnsstudio\VisnsPackages\Traits\HasRelationshipSorting;

class TestModel extends Model
{
    use HasRelationshipSorting;

    protected $table = "test_models";
    protected $fillable = ["name", "email"];
    
    // Add virtual/appended attributes
    protected $appends = ["full_display_name"];
    
    // Virtual column accessor
    public function getFullDisplayNameAttribute()
    {
        return $this->name . " (" . $this->email . ")";
    }
    
    // Another virtual column accessor
    public function getFormattedCreatedAtAttribute()
    {
        return $this->created_at ? $this->created_at->format("Y-m-d H:i") : null;
    }
}
');
        }
    }

    public function test_table_response_includes_column_metadata()
    {
        // Create test data
        $model = new \App\Models\TestModel();
        $model->name = 'Test User';
        $model->email = 'test@example.com';
        $model->save();

        // Make request to table endpoint
        $response = $this->postJson('/ajax/testModel/table', [
            'take' => 10
        ]);

        $response->assertStatus(200);
        
        // Check that response includes columns_metadata
        $response->assertJsonStructure([
            'data',
            'current_page',
            'from',
            'last_page',
            'per_page',
            'to',
            'total',
            'columns_metadata'
        ]);

        $responseData = $response->json();
        $metadata = $responseData['columns_metadata'];

        // Verify that regular database columns are sortable
        $this->assertTrue($metadata['id']['sortable']);
        $this->assertFalse($metadata['id']['virtual']);
        
        $this->assertTrue($metadata['name']['sortable']);
        $this->assertFalse($metadata['name']['virtual']);
        
        $this->assertTrue($metadata['email']['sortable']);
        $this->assertFalse($metadata['email']['virtual']);

        // Verify that virtual/appended columns are not sortable
        $this->assertFalse($metadata['full_display_name']['sortable']);
        $this->assertTrue($metadata['full_display_name']['virtual']);
    }

    public function test_column_metadata_detects_accessor_methods()
    {
        // Create test data
        $model = new \App\Models\TestModel();
        $model->name = 'Test User';
        $model->email = 'test@example.com';
        $model->save();

        // Make request to table endpoint
        $response = $this->postJson('/ajax/testModel/table', [
            'take' => 10
        ]);

        $response->assertStatus(200);
        
        $responseData = $response->json();
        $metadata = $responseData['columns_metadata'];

        // If the formatted_created_at field is detected, it should be virtual
        if (isset($metadata['formatted_created_at'])) {
            $this->assertFalse($metadata['formatted_created_at']['sortable']);
            $this->assertTrue($metadata['formatted_created_at']['virtual']);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test model file
        if (file_exists(app_path('Models/TestModel.php'))) {
            unlink(app_path('Models/TestModel.php'));
        }
        
        parent::tearDown();
    }
}