<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Security;

use App\Models\SecVault;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Visnsstudio\VisnsPackages\Support\RelationGuard;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * 4.17.4: a dynamic-entity filter, sort or `columns` request cannot read a
 * column the model hides, a column whose name says it is a secret, or a field
 * the model excludes - on the entity or on a related model. And a filter
 * through something that is not a relation matches nothing.
 */
class HiddenColumnExposureTest extends TestCase
{
    private User $ann;
    private User $bob;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.dynamic_entities', ['secVaults']);
        $app['config']->set('visns-packages.entity_default_middleware', ['auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RelationGuard::flush();

        Schema::create('sec_vaults', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('pin')->nullable();
            $t->string('api_token')->nullable();
            $t->string('internal_code')->nullable();
            $t->timestamps();
        });

        $this->ann = User::create(['name' => 'Ann', 'email' => 'ann@example.test', 'password' => 'aaaa-hash']);
        $this->bob = User::create(['name' => 'Bob', 'email' => 'bob@example.test', 'password' => 'bbbb-hash']);

        DB::table('sec_vaults')->insert([
            ['name' => 'Alpha', 'user_id' => $this->ann->id, 'pin' => '1111', 'api_token' => 'tok-a', 'internal_code' => 'IC-A'],
            ['name' => 'Beta', 'user_id' => $this->bob->id, 'pin' => '2222', 'api_token' => 'tok-b', 'internal_code' => 'IC-B'],
        ]);

        $this->actingAs(User::create([
            'name' => 'Dana', 'email' => 'dana@example.test', 'password' => Hash::make('secret-password'),
        ]));
    }

    private function table(array $body)
    {
        return $this->postJson('/ajax/secVaults/table', $body + ['take' => 50])->assertOk();
    }

    private function names(array $body): array
    {
        return collect($this->table($body)->json('data'))->pluck('name')->sort()->values()->all();
    }

    #[Test]
    public function a_filter_on_a_related_users_password_is_ignored(): void
    {
        // Both forms of "filter through a relation".
        $this->assertSame(['Alpha', 'Beta'], $this->names([
            'where' => [['id' => 'user.password', 'operator' => 'startsWith', 'value' => 'aaaa']],
        ]));
        $this->assertSame(['Alpha', 'Beta'], $this->names([
            'where' => [['id' => 'password', 'operator' => 'contains', 'value' => 'aaaa', 'whereHas' => 'user']],
        ]));
        // Nor through an orKey.
        $this->assertSame(['Alpha'], $this->names([
            'where' => [['id' => 'name', 'value' => 'Ann', 'whereHas' => 'user', 'orKey' => 'password']],
        ]));
    }

    #[Test]
    public function a_filter_on_a_hidden_or_secret_column_of_the_entity_is_ignored(): void
    {
        $this->assertSame(['Alpha', 'Beta'], $this->names(['where' => [['id' => 'pin', 'value' => '1111']]]));
        $this->assertSame(['Alpha', 'Beta'], $this->names(['where' => [['id' => 'api_token', 'value' => 'tok-a']]]));
        $this->assertSame(['Alpha', 'Beta'], $this->names([
            'where' => [['id' => 'sec_vaults.pin', 'value' => '1111']],
        ]));
    }

    #[Test]
    public function ordinary_columns_still_filter(): void
    {
        $this->assertSame(['Alpha'], $this->names(['where' => [['id' => 'name', 'value' => 'Alpha']]]));
        $this->assertSame(['Beta'], $this->names(['where' => [['id' => 'user.name', 'value' => 'Bob']]]));
        $this->assertSame(['Beta'], $this->names([
            'where' => [['id' => 'email', 'operator' => 'contains', 'value' => 'bob', 'whereHas' => 'user']],
        ]));
    }

    #[Test]
    public function a_sort_on_a_hidden_column_never_reaches_the_database(): void
    {
        $seen = [];
        DB::listen(function ($q) use (&$seen) { $seen[] = strtolower($q->sql); });

        foreach (['pin', 'api_token', 'user.password', 'sec_vaults.pin'] as $key) {
            $this->table(['sortBy' => $key, 'sort' => 'desc']);
        }

        foreach ($seen as $sql) {
            $this->assertStringNotContainsString('order by "pin"', $sql);
            $this->assertStringNotContainsString('"pin" desc', $sql);
            $this->assertStringNotContainsString('api_token', $sql);
            $this->assertStringNotContainsString('password', $sql);
        }
    }

    #[Test]
    public function the_relationship_sorting_trait_refuses_a_hidden_related_column(): void
    {
        $sql = strtolower(SecVault::query()->customOrder('user.password', 'desc')->toSql());
        $this->assertStringNotContainsString('password', $sql);

        $sql = strtolower(SecVault::query()->customOrder('pin', 'desc')->toSql());
        $this->assertStringNotContainsString('pin', $sql);
    }

    #[Test]
    public function ordinary_columns_still_sort(): void
    {
        $this->assertSame(['Beta', 'Alpha'], collect($this->table(['sortBy' => 'name', 'sort' => 'desc'])->json('data'))->pluck('name')->all());
        $this->assertSame(['Beta', 'Alpha'], collect($this->table(['sortBy' => 'user.name', 'sort' => 'desc'])->json('data'))->pluck('name')->all());
        $this->assertSame(['Alpha', 'Beta'], collect($this->table(['sortBy' => 'user.name', 'sort' => 'asc'])->json('data'))->pluck('name')->all());
    }

    #[Test]
    public function naming_columns_cannot_bring_back_an_excluded_field(): void
    {
        $rows = $this->table(['columns' => ['id', 'name', 'internal_code', 'pin']])->json('data');

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('internal_code', $row);
            $this->assertArrayNotHasKey('pin', $row);
            $this->assertArrayHasKey('name', $row);
            // The rest of the request still narrows the select.
            $this->assertArrayNotHasKey('user_id', $row);
        }

        // Comma-separated form, and the list endpoint without pagination.
        $request = \Illuminate\Http\Request::create('/ajax/secVaults/list', 'POST', ['columns' => 'id,name,internal_code']);
        $rows = (new \Visnsstudio\VisnsPackages\Controllers\DynamicController($request))
            ->list($request)
            ->getData(true);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('internal_code', $row);
            $this->assertArrayHasKey('name', $row);
        }

        // And without `columns` the exclusion applies as it always did.
        foreach ($this->table([])->json('data') as $row) {
            $this->assertArrayNotHasKey('internal_code', $row);
        }
    }

    #[Test]
    public function a_dropdown_cannot_return_a_hidden_field(): void
    {
        $data = $this->postJson('/ajax/secVaults/dropdown', ['fields' => ['id', 'pin']])->assertOk()->json('data');

        foreach ($data as $row) {
            $this->assertArrayNotHasKey('pin', $row);
            $this->assertNotContains('1111', array_map('strval', array_values($row)));
        }
    }

    #[Test]
    public function a_filter_through_an_unknown_relation_returns_no_rows(): void
    {
        $this->assertSame([], $this->names(['where' => [['id' => 'nope.name', 'value' => 'Ann']]]));
        $this->assertSame([], $this->names(['where' => [['whereHas' => 'nope']]]));
        $this->assertSame([], $this->names(['where' => [['whereDoesntHave' => 'nope']]]));
        $this->assertSame([], $this->names(['where' => [['id' => 'name', 'value' => 'x', 'whereHas' => ['user', 'nope']]]]));

        // An unknown sort relation is still merely ignored.
        $this->assertSame(['Alpha', 'Beta'], $this->names(['sortBy' => 'nope.name', 'sort' => 'asc']));
    }
}
