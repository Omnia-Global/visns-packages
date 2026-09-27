<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Security;

use App\Models\SecOwner;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * 4.17.4: a merge's `field_overrides` only write the model's $fillable columns.
 */
class MergeOverrideTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.dynamic_entities', ['secOwners']);
        $app['config']->set('visns-packages.entity_default_middleware', ['auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sec_owners', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->boolean('is_admin')->default(false);
            $t->timestamps();
        });

        $this->actingAs(User::create([
            'name' => 'Dana', 'email' => 'dana@example.test', 'password' => Hash::make('secret-password'),
        ]));
    }

    #[Test]
    public function overrides_are_limited_to_fillable_columns(): void
    {
        $target = SecOwner::create(['name' => 'Ann']);
        $source = SecOwner::create(['name' => 'Annie']);

        $this->postJson('/ajax/secOwners/merge', [
            'target_id' => $target->id,
            'source_id' => $source->id,
            'field_overrides' => ['name' => 'Ann Merged', 'is_admin' => 1],
        ])->assertSuccessful();

        // The merge writes into the SOURCE record (the endpoint merges the
        // target into it).
        $merged = SecOwner::find($source->id);

        $this->assertNotNull($merged);
        $this->assertSame('Ann Merged', $merged->name, 'a fillable override still applies');
        $this->assertFalse((bool) $merged->is_admin, 'is_admin is not fillable');
        $this->assertSame(0, SecOwner::where('is_admin', true)->count());
    }
}
