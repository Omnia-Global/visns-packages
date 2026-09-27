<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\Security;

use App\Models\SecOwner;
use App\Models\SecPart;
use App\Models\SecWidget;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Visnsstudio\VisnsPackages\Controllers\DynamicController;
use Visnsstudio\VisnsPackages\Support\RelationGuard;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * 4.17.2: request input no longer reaches three unsafe places in the dynamic
 * entity controller - relation names Eloquent would call as methods, nested
 * writes onto related rows, and SQL text.
 */
class RequestInputHardeningTest extends TestCase
{
    private const INJECTED = "x') OR 1=1 --";

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('visns-packages.dynamic_entities', ['secWidgets']);
        $app['config']->set('visns-packages.entity_default_middleware', ['auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RelationGuard::flush();
        SecWidget::$purged = 0;

        Schema::create('sec_owners', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->boolean('is_admin')->default(false);
            $t->timestamps();
        });
        Schema::create('sec_widgets', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->text('details')->nullable();
            $t->timestamps();
        });
        Schema::create('sec_parts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('widget_id');
            $t->string('name')->nullable();
            $t->timestamps();
        });
        Schema::create('sec_notes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('widget_id');
            $t->string('body')->nullable();
            $t->timestamps();
        });

        $this->actingAs(User::create([
            'name' => 'Dana', 'email' => 'dana@example.test', 'password' => Hash::make('secret-password'),
        ]));
    }

    /** @return array{0: SecOwner, 1: SecOwner, 2: SecWidget, 3: SecWidget} */
    private function seedWidgets(): array
    {
        $ann = SecOwner::create(['name' => 'Ann']);
        $bob = SecOwner::create(['name' => 'Bob']);
        $alpha = SecWidget::create(['name' => 'Alpha', 'owner_id' => $bob->id, 'details' => ['status' => 'open']]);
        $beta = SecWidget::create(['name' => 'Beta', 'owner_id' => $ann->id, 'details' => ['status' => 'closed']]);
        SecPart::create(['widget_id' => $alpha->id, 'name' => 'Bolt']);

        return [$ann, $bob, $alpha, $beta];
    }

    private function table(array $body)
    {
        return $this->postJson('/ajax/secWidgets/table', $body + ['take' => 50]);
    }

    private function names($response): array
    {
        return collect($response->json('data'))->pluck('name')->all();
    }

    private function controller(): DynamicController
    {
        return new DynamicController(Request::create('/ajax/secWidgets/table', 'POST'));
    }

    // ---- Fix A: relation names -------------------------------------------

    #[Test]
    public function a_where_has_or_where_doesnt_have_naming_a_method_is_never_called_and_matches_nothing(): void
    {
        $this->seedWidgets();

        // 4.17.4: a filter through something that is not a relation fails
        // CLOSED - it matches no rows rather than being skipped, which would
        // widen the result set.
        foreach ([
            ['where' => [['whereHas' => 'purge']]],
            ['where' => [['whereDoesntHave' => 'purge']]],
            ['where' => [['whereHas' => ['parts', 'purge']]]],
            ['where' => [['id' => 'name', 'value' => 'Alpha', 'whereHas' => 'purge']]],
            ['where' => [['id' => 'purge.name', 'value' => 'x']]],
            ['where' => [['group' => true, 'operator' => 'OR', 'conditions' => [['whereDoesntHave' => 'promoteOwner']]]]],
        ] as $body) {
            $this->assertSame([], $this->names($this->table($body)->assertOk()), json_encode($body));
        }

        $this->assertSame([], $this->postJson('/ajax/secWidgets/dropdown', [
            'where' => [['id' => 'whereHas', 'value' => 'purge']],
        ])->assertOk()->json('data'));

        $this->assertSame(0, SecWidget::$purged);
        $this->assertSame(2, SecWidget::count());
        $this->assertSame(0, SecOwner::where('is_admin', true)->count());
    }

    #[Test]
    public function a_real_relation_still_filters(): void
    {
        $this->seedWidgets();

        $this->assertSame(['Alpha'], $this->names($this->table(['where' => [['whereHas' => 'parts']]])));
        $this->assertSame(['Beta'], $this->names($this->table(['where' => [['whereDoesntHave' => 'parts']]])));
        $this->assertSame(['Alpha'], $this->names($this->table(['where' => [['id' => 'owner.name', 'value' => 'Bob']]])));
        $this->assertSame(['Alpha'], $this->names($this->table([
            'where' => [['id' => 'name', 'operator' => 'contains', 'value' => 'Bolt', 'whereHas' => 'parts']],
        ])));
        // A real relation with a column that is not on the related table is skipped, not a 500.
        $this->table(['where' => [['id' => 'owner.nope', 'value' => 'Bob']]])->assertOk();
    }

    #[Test]
    public function a_merge_naming_a_method_as_a_relationship_is_refused_before_anything_moves(): void
    {
        [, , $alpha, $beta] = $this->seedWidgets();

        $this->postJson('/ajax/secWidgets/merge', [
            'target_id' => $alpha->id, 'source_id' => $beta->id, 'relationships' => ['purge'],
        ])->assertStatus(422);

        $this->assertSame(0, SecWidget::$purged);
        $this->assertSame(2, SecWidget::count());
    }

    #[Test]
    public function a_gallery_upload_cannot_name_a_method_as_its_relation(): void
    {
        [, , $alpha] = $this->seedWidgets();

        $this->postJson("/ajax/secWidgets/updateGallery/{$alpha->id}", [
            'key' => 'tmp/2f1c5a4e-uuid', 'uuid' => 'u', 'extension' => 'png', 'filename' => 'a.png',
            'fileable_field' => 'purge', 'fileable_type' => SecWidget::class,
        ])->assertStatus(422);

        $this->assertSame(0, SecWidget::$purged);
        $this->assertSame(2, SecWidget::count());
    }

    #[Test]
    public function a_json_key_naming_a_method_is_refused(): void
    {
        [, , $alpha] = $this->seedWidgets();

        $this->postJson('/ajax/secWidgets/json/get', ['dataId' => $alpha->id, 'id' => 1, 'key' => 'purge'])->assertStatus(422);
        $this->postJson('/ajax/secWidgets/json/table', ['where' => [
            ['id' => 'id', 'value' => $alpha->id], ['id' => 'dataKey', 'value' => 'purge'],
        ]])->assertOk();

        $this->assertSame(0, SecWidget::$purged);
        $this->assertSame(2, SecWidget::count());
    }

    // ---- Fix B: nested writes ---------------------------------------------

    #[Test]
    public function a_nested_object_on_update_cannot_write_the_related_row_or_call_a_method(): void
    {
        [$ann, $bob, $alpha] = $this->seedWidgets();

        $this->putJson("/ajax/secWidgets/{$alpha->id}", [
            'name' => 'Alpha 2',
            // Points the widget at Ann - and tries to rewrite Ann on the way.
            'owner' => ['id' => $ann->id, 'name' => 'Hacked', 'is_admin' => 1],
            // Not a relation: must never be called.
            'promoteOwner' => ['x' => 1],
            'purge' => ['x' => 1],
        ])->assertOk();

        $ann->refresh();
        $this->assertSame('Ann', $ann->name);
        $this->assertFalse((bool) $ann->is_admin);
        $this->assertSame(0, SecOwner::where('is_admin', true)->count());
        $this->assertSame(0, SecWidget::$purged);
        $this->assertSame(2, SecWidget::count());

        $alpha->refresh();
        $this->assertSame('Alpha 2', $alpha->name);
        $this->assertSame($ann->id, (int) $alpha->owner_id, 'a nested BelongsTo id still sets the foreign key');
        $this->assertSame('Bob', $bob->refresh()->name);
    }

    #[Test]
    public function a_nested_belongs_to_id_sets_the_foreign_key_on_store_and_writes_nothing_else(): void
    {
        [$ann] = $this->seedWidgets();

        $this->postJson('/ajax/secWidgets', [
            'name' => 'Gamma',
            'owner' => ['id' => $ann->id, 'name' => 'Hacked', 'is_admin' => 1],
        ])->assertSuccessful();

        $this->assertSame($ann->id, (int) SecWidget::where('name', 'Gamma')->value('owner_id'));
        $this->assertSame('Ann', $ann->refresh()->name);
        $this->assertFalse((bool) $ann->is_admin);

        // Without an id and without opting in, no related row is created.
        $this->postJson('/ajax/secWidgets', ['name' => 'Delta', 'owner' => ['name' => 'New', 'is_admin' => 1]])->assertSuccessful();
        $this->assertSame(2, SecOwner::count());
        $this->assertNull(SecWidget::where('name', 'Delta')->value('owner_id'));

        // An id that does not exist is not adopted and creates nothing.
        $this->postJson('/ajax/secWidgets', ['name' => 'Eps', 'owner' => ['id' => 999, 'name' => 'Ghost']])->assertSuccessful();
        $this->assertSame(2, SecOwner::count());
    }

    #[Test]
    public function an_opted_in_relation_writes_through_fill_and_only_onto_the_row_already_related(): void
    {
        config(['visns-packages.entity_config.secWidgets.nested_writable' => ['owner', 'note']]);
        [$ann, $bob, $alpha] = $this->seedWidgets();

        // Alpha belongs to Bob: posting Bob's id may write Bob, through $fillable.
        $this->putJson("/ajax/secWidgets/{$alpha->id}", [
            'owner' => ['id' => $bob->id, 'name' => 'Robert', 'is_admin' => 1],
            'note' => ['body' => 'Hello'],
        ])->assertOk();
        $bob->refresh();
        $this->assertSame('Robert', $bob->name);
        $this->assertFalse((bool) $bob->is_admin, 'is_admin is not fillable');
        $this->assertSame('Hello', $alpha->refresh()->note->body);

        // Posting Ann's id only re-points the widget; Ann is not written.
        $this->putJson("/ajax/secWidgets/{$alpha->id}", ['owner' => ['id' => $ann->id, 'name' => 'Hacked']])->assertOk();
        $this->assertSame('Ann', $ann->refresh()->name);
        $this->assertSame($ann->id, (int) $alpha->refresh()->owner_id);
    }

    // ---- Fix C: SQL ---------------------------------------------------------

    #[Test]
    public function a_contain_json_filter_binds_its_path_and_drops_a_malicious_one(): void
    {
        $this->seedWidgets();
        $controller = $this->controller();
        $apply = new \ReflectionMethod($controller, 'applyFilterCondition');

        $query = SecWidget::query();
        $apply->invoke($controller, $query, ['id' => 'details.status', 'operator' => 'contain_json', 'value' => 'open']);
        $this->assertStringContainsString('JSON_UNQUOTE(JSON_EXTRACT("sec_widgets"."details", ?)) like ?', $query->toSql());
        $this->assertSame(['$.status', '%open%'], $query->getBindings());

        foreach ([
            'details.' . self::INJECTED,
            'details.status\')) OR 1=1 --',
            "nope.status",
            "details.a.b-c",
        ] as $id) {
            $query = SecWidget::query();
            $apply->invoke($controller, $query, ['id' => $id, 'operator' => 'contain_json', 'value' => 'open']);
            $this->assertStringNotContainsString('OR 1=1', $query->toSql(), $id);
            $this->assertStringNotContainsString('JSON_EXTRACT', $query->toSql(), $id);
        }

        // Over HTTP the malicious filter is skipped, not executed.
        $seen = [];
        DB::listen(function ($q) use (&$seen) { $seen[] = $q->sql; });
        $this->table(['where' => [['id' => 'details.' . self::INJECTED, 'operator' => 'contain_json', 'value' => 'a']]])->assertOk();
        foreach ($seen as $sql) {
            $this->assertStringNotContainsString('OR 1=1', $sql);
        }
    }

    #[Test]
    public function a_json_arrow_column_needs_a_real_base_column_and_plain_segments(): void
    {
        $this->seedWidgets();
        $controller = $this->controller();
        $apply = new \ReflectionMethod($controller, 'applyFilterCondition');

        $query = SecWidget::query();
        $apply->invoke($controller, $query, ['id' => 'details->status', 'value' => 'open']);
        $this->assertStringContainsString('json_extract', strtolower($query->toSql()));

        foreach (['details->' . self::INJECTED, 'nope->status'] as $id) {
            $query = SecWidget::query();
            $apply->invoke($controller, $query, ['id' => $id, 'value' => 'open']);
            $this->assertStringNotContainsString('OR 1=1', $query->toSql());
            $this->assertStringNotContainsString('where', strtolower($query->toSql()), $id);
        }
    }

    #[Test]
    public function a_sort_direction_or_dotted_key_carrying_sql_never_reaches_the_database(): void
    {
        $this->seedWidgets();
        $seen = [];
        DB::listen(function ($q) use (&$seen) { $seen[] = $q->sql; });

        foreach ([
            ['sortBy' => 'name', 'sort' => 'desc; DROP TABLE sec_widgets'],
            ['sortBy' => 'details.status', 'sort' => 'asc, (SELECT 1)'],
            ['sortBy' => 'details.' . self::INJECTED, 'sort' => 'asc'],
            ['sortBy' => 'purge.name', 'sort' => 'asc'],
            ['sortBy' => 'owner.' . self::INJECTED, 'sort' => 'asc'],
        ] as $body) {
            $this->table($body)->assertOk();
        }

        foreach ($seen as $sql) {
            $this->assertStringNotContainsString('DROP TABLE', $sql);
            $this->assertStringNotContainsString('SELECT 1', $sql);
            $this->assertStringNotContainsString('OR 1=1', $sql);
        }
        $this->assertSame(0, SecWidget::$purged);
        $this->assertSame(2, SecWidget::count());
    }

    #[Test]
    public function valid_directions_and_keys_still_sort(): void
    {
        $this->seedWidgets();

        $this->assertSame(['Beta', 'Alpha'], $this->names($this->table(['sortBy' => 'name', 'sort' => 'desc'])));
        $this->assertSame(['Alpha', 'Beta'], $this->names($this->table(['sortBy' => 'name', 'sort' => 'ASC'])));
        $this->assertSame(['Beta', 'Alpha'], $this->names($this->table(['sortBy' => 'details.status', 'sort' => 'asc'])));
        $this->assertSame(['Alpha', 'Beta'], $this->names($this->table(['sortBy' => 'details.status', 'sort' => 'desc'])));
        $this->assertSame(['Beta', 'Alpha'], $this->names($this->table(['sortBy' => 'owner.name', 'sort' => 'asc'])));
        $this->assertSame(['Alpha', 'Beta'], $this->names($this->table(['sortBy' => 'owner.name', 'sort' => 'desc'])));
    }
}
