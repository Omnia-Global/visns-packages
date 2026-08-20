<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class DynamicControllerNestedObjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tables if they don't exist
        if (!Schema::hasTable('clients')) {
            Schema::create('clients', function (Blueprint $table) {
                $table->id();
                $table->string('firstname')->nullable();
                $table->string('surname')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('opportunities')) {
            Schema::create('opportunities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('client_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('opportunity_type_id')->nullable();
                $table->string('mobile')->nullable();
                $table->string('home_email')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                
                $table->foreign('client_id')->references('id')->on('clients');
            });
        }

        // Create test models if they don't exist
        if (!class_exists('App\Models\Client')) {
            file_put_contents(app_path('Models/Client.php'), '<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;
    
    protected $fillable = [
        "firstname",
        "surname",
    ];
    
    public function opportunities()
    {
        return $this->hasMany(Opportunity::class);
    }
    
    public function validationRules($action, $data = [])
    {
        return [
            "firstname" => ["nullable", "string"],
            "surname" => ["nullable", "string"],
        ];
    }
    
    public function loadableRelations()
    {
        return [];
    }
}');
        }

        if (!class_exists('App\Models\Opportunity')) {
            file_put_contents(app_path('Models/Opportunity.php'), '<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Opportunity extends Model
{
    use HasFactory;
    
    protected $fillable = [
        "client_id",
        "user_id",
        "opportunity_type_id",
        "mobile",
        "home_email",
        "notes",
    ];
    
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
    
    public function validationRules($action, $data = [])
    {
        return [
            "client_id" => ["nullable", "exists:clients,id"],
            "user_id" => ["nullable", "exists:users,id"],
            "opportunity_type_id" => ["nullable", "integer"],
            "mobile" => ["nullable", "string"],
            "home_email" => ["nullable", "string"],
            "notes" => ["nullable", "string"],
        ];
    }
    
    public function loadableRelations()
    {
        return ["client"];
    }
}');
        }
    }

    /** @test */
    public function it_can_update_a_model_with_nested_object_for_belongs_to_relationship()
    {
        // Create a user for authentication
        $user = User::factory()->create();
        $this->actingAs($user);
        
        // Create a client
        $client = DB::table('clients')->insertGetId([
            'firstname' => 'John',
            'surname' => 'Doe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Create an opportunity
        $opportunity = DB::table('opportunities')->insertGetId([
            'client_id' => $client,
            'user_id' => $user->id,
            'opportunity_type_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Prepare the update data with nested client object
        $updateData = [
            'id' => $opportunity,
            'user_id' => $user->id,
            'opportunity_type_id' => 1,
            'mobile' => null,
            'home_email' => null,
            'notes' => null,
            'client' => [
                'firstname' => 'Clair',
                'surname' => 'Wilson',
            ],
            '_method' => 'PUT'
        ];
        
        // Make the request to update the opportunity
        $response = $this->json('POST', '/ajax/opportunities/' . $opportunity, $updateData);
        
        // Assert the response is successful
        $response->assertStatus(200);
        
        // Check that the client was updated
        $this->assertDatabaseHas('clients', [
            'id' => $client,
            'firstname' => 'Clair',
            'surname' => 'Wilson',
        ]);
        
        // Check that the opportunity still references the same client
        $this->assertDatabaseHas('opportunities', [
            'id' => $opportunity,
            'client_id' => $client,
        ]);
    }

    /** @test */
    public function it_switches_a_belongs_to_to_another_existing_row_when_the_nested_object_carries_a_different_key()
    {
        // Regression: a job form echoing back a stale `job_stage` object
        // ({id: 2, label: Production}) while the job had already moved to
        // stage 3 used to run `update job_stages set id = 2 where id = 3`,
        // tripping the primary-key constraint. The nested key names the row
        // the parent should point at; it is never written onto another row.
        $user = User::factory()->create();
        $this->actingAs($user);

        $john = DB::table('clients')->insertGetId([
            'firstname' => 'John',
            'surname' => 'Doe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $jane = DB::table('clients')->insertGetId([
            'firstname' => 'Jane',
            'surname' => 'Roe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $opportunity = DB::table('opportunities')->insertGetId([
            'client_id' => $jane,
            'user_id' => $user->id,
            'opportunity_type_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->json('POST', '/ajax/opportunities/' . $opportunity, [
            'id' => $opportunity,
            'user_id' => $user->id,
            'opportunity_type_id' => 1,
            'client' => [
                'id' => $john,
                'firstname' => 'John',
                'surname' => 'Doe',
            ],
            '_method' => 'PUT',
        ]);

        $response->assertStatus(200);

        // Parent now points at the row named by the nested key...
        $this->assertDatabaseHas('opportunities', [
            'id' => $opportunity,
            'client_id' => $john,
        ]);
        // ...and both client rows survive untouched.
        $this->assertDatabaseHas('clients', ['id' => $john, 'firstname' => 'John']);
        $this->assertDatabaseHas('clients', ['id' => $jane, 'firstname' => 'Jane']);
        $this->assertSame(2, DB::table('clients')->count());
    }

    /** @test */
    public function it_can_create_a_model_with_nested_object_for_belongs_to_relationship()
    {
        // Create a user for authentication
        $user = User::factory()->create();
        $this->actingAs($user);
        
        // Prepare the create data with nested client object
        $createData = [
            'user_id' => $user->id,
            'opportunity_type_id' => 1,
            'mobile' => null,
            'home_email' => null,
            'notes' => null,
            'client' => [
                'firstname' => 'Clair',
                'surname' => 'Wilson',
            ],
        ];
        
        // Make the request to create a new opportunity
        $response = $this->json('POST', '/ajax/opportunities', $createData);
        
        // Assert the response is successful
        $response->assertStatus(200);
        
        // Get the response data
        $responseData = $response->json();
        
        // Check that a new client was created
        $this->assertDatabaseHas('clients', [
            'firstname' => 'Clair',
            'surname' => 'Wilson',
        ]);
        
        // Get the client ID
        $client = DB::table('clients')
            ->where('firstname', 'Clair')
            ->where('surname', 'Wilson')
            ->first();
        
        // Check that the opportunity references the new client
        $this->assertDatabaseHas('opportunities', [
            'id' => $responseData['data']['id'],
            'client_id' => $client->id,
        ]);
    }
}
