<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Security;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Visnsstudio\VisnsPackages\Support\PageSize;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * 4.18.0: a list endpoint's page size is capped (visns-packages.max_page_size,
 * 200). `take=100000` used to be the whole table in one response. An absent
 * `take` keeps the endpoint's default; an oversized one is capped, not refused.
 */
class PageSizeCapTest extends TestCase
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

        DB::table('sec_owners')->insert(
            array_map(fn($i) => ['name' => 'Owner ' . $i], range(1, 230))
        );

        $this->actingAs(User::create([
            'name' => 'Dana', 'email' => 'dana@example.test', 'password' => Hash::make('secret-password'),
        ]));
    }

    #[Test]
    public function the_helper_defaults_caps_and_ignores_junk(): void
    {
        $this->assertSame(10, PageSize::from(Request::create('/', 'POST')));
        $this->assertSame(25, PageSize::from(Request::create('/', 'POST', ['take' => 25])));
        $this->assertSame(200, PageSize::from(Request::create('/', 'POST', ['take' => 100000])));
        $this->assertSame(10, PageSize::from(Request::create('/', 'POST', ['take' => 'all'])));
        $this->assertSame(10, PageSize::from(Request::create('/', 'POST', ['take' => -5])));
        $this->assertSame(10, PageSize::from(Request::create('/', 'POST', ['take' => ''])));

        config(['visns-packages.max_page_size' => 50]);
        $this->assertSame(50, PageSize::from(Request::create('/', 'POST', ['take' => 100000])));
        $this->assertSame(50, PageSize::clamp(null, 500));
    }

    #[Test]
    public function the_dynamic_table_caps_an_oversized_take(): void
    {
        $page = $this->postJson('/ajax/secOwners/table', ['take' => 100000])->assertOk();

        $this->assertCount(200, $page->json('data'));
        $this->assertSame(200, (int) $page->json('per_page'));
        $this->assertSame(230, (int) $page->json('total'));
    }

    #[Test]
    public function the_dynamic_table_keeps_its_default_when_take_is_absent(): void
    {
        $page = $this->postJson('/ajax/secOwners/table', [])->assertOk();

        $this->assertCount(10, $page->json('data'));
        $this->assertSame(10, (int) $page->json('per_page'));
    }

    #[Test]
    public function an_ordinary_take_is_untouched(): void
    {
        $this->assertCount(50, $this->postJson('/ajax/secOwners/table', ['take' => 50])->assertOk()->json('data'));
    }

    #[Test]
    public function the_permission_and_role_tables_cap_an_oversized_take(): void
    {
        foreach (range(1, 205) as $i) {
            Permission::create(['name' => 'perm ' . $i, 'guard_name' => 'web']);
            Role::create(['name' => 'role ' . $i, 'guard_name' => 'web']);
        }

        foreach (['/ajax/permissions/table', '/ajax/roles/table'] as $uri) {
            $big = $this->postJson($uri, ['take' => 100000, 'sortBy' => 'id', 'sort' => 'asc'])->assertOk();
            $this->assertCount(200, $big->json('data'), $uri);

            $default = $this->postJson($uri, ['sortBy' => 'id', 'sort' => 'asc'])->assertOk();
            $this->assertCount(10, $default->json('data'), $uri);
        }
    }
}
