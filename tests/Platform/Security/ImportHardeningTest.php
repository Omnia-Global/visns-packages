<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Security;

use App\Models\SecOwner;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Visnsstudio\VisnsPackages\Controllers\ImportController;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * 4.17.4: the generic import writes only to a server-configured model and only
 * its $fillable columns, whatever the request's mapping or model_config says.
 */
class ImportHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sec_owners', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->boolean('is_admin')->default(false);
            $t->timestamps();
        });

        // The package does not route the controller; an application does.
        Route::middleware('web')->post('/test-import', [ImportController::class, 'processImport']);

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function import(string $target, array $mapping, array $modelConfig = [])
    {
        $csv = UploadedFile::fake()->createWithContent(
            'rows.csv',
            "name,is_admin,id\nAnn,1,500\nBob,1,501\n"
        );

        return $this->post('/test-import', [
            'file' => $csv,
            'mapping' => json_encode($mapping),
            'model_config' => json_encode($modelConfig ?: [['id' => 'name', 'type' => 'text', 'required' => true]]),
            'target_model' => $target,
        ], ['Accept' => 'application/json']);
    }

    #[Test]
    public function only_fillable_columns_are_written(): void
    {
        config()->set('visns-packages.import.models', ['sec_owners' => SecOwner::class]);

        $this->import('sec_owners', ['name' => 'name', 'is_admin' => 'is_admin', 'id' => 'id'])
            ->assertOk()
            ->assertJsonPath('imported', 2);

        $this->assertSame(['Ann', 'Bob'], SecOwner::orderBy('name')->pluck('name')->all());
        $this->assertSame(0, SecOwner::where('is_admin', true)->count(), 'is_admin is not fillable');
        $this->assertSame(0, SecOwner::whereIn('id', [500, 501])->count(), 'id is not fillable');
        $this->assertNotNull(SecOwner::first()->created_at, 'saved through the model');
    }

    #[Test]
    public function a_target_with_no_configured_model_is_refused(): void
    {
        User::create(['email' => 'dana@example.test', 'password' => Hash::make('x')]);

        $this->import('users', ['name' => 'name'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->import('sec_owners', ['name' => 'name'])->assertStatus(422);

        $this->assertSame(1, User::count());
        $this->assertSame(0, SecOwner::count());
    }

    #[Test]
    public function model_config_cannot_widen_what_is_written(): void
    {
        config()->set('visns-packages.import.models', ['sec_owners' => SecOwner::class]);

        $this->import('sec_owners', ['name' => 'name', 'is_admin' => 'is_admin'], [
            ['id' => 'name', 'type' => 'text', 'required' => true],
            ['id' => 'is_admin', 'type' => 'text', 'fillable' => true, 'table' => 'users'],
        ])->assertOk();

        $this->assertSame(0, SecOwner::where('is_admin', true)->count());
        $this->assertSame(2, SecOwner::count());
    }

    #[Test]
    public function a_dynamic_entitys_table_is_a_target_through_its_model(): void
    {
        config()->set('visns-packages.dynamic_entities', ['secOwners']);

        $this->import('sec_owners', ['name' => 'name', 'is_admin' => 'is_admin'])->assertOk();

        $this->assertSame(2, SecOwner::count());
        $this->assertSame(0, SecOwner::where('is_admin', true)->count());
    }
}
