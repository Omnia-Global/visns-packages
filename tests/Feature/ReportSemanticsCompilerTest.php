<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\QueryCompiler;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\SemanticException;
use Visnsstudio\VisnsPackages\Services\ReportSemantics\SemanticModel;
use Visnsstudio\VisnsPackages\VisnsPackagesServiceProvider;

/**
 * End-to-end coverage for the report definition v2 compiler.
 *
 * The queries run against an in-memory SQLite database, so the resolution,
 * filtering, grouping and row-shaping rules are checked on real results
 * rather than on a string. The two MySQL-only pieces - JSON extraction and
 * the zero-foreign-key join - are asserted on the generated SQL instead,
 * because SQLite has no JSON_UNQUOTE.
 */
class ReportSemanticsCompilerTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [VisnsPackagesServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // The schema inspector caches column listings; an array store keeps
        // that per-process and out of the filesystem.
        $app['config']->set('cache.default', 'array');

        $app['config']->set(
            'visns-packages.report_semantics',
            $this->semanticConfig()
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->seedRows();
    }

    /**
     * The registry every test in this file reports against.
     *
     * @return array
     */
    private function semanticConfig(): array
    {
        return [
            'entities' => [
                'clients' => [
                    'label' => 'Client',
                    'plural' => 'Clients',
                    'description' => 'People the practice advises',
                    'table' => 'clients',
                    'fields' => [
                        'firstname' => ['label' => 'First name', 'type' => 'text'],
                        'surname' => ['label' => 'Surname', 'type' => 'text'],
                        'home_email' => ['label' => 'Home email', 'type' => 'text'],
                        'work_email' => ['label' => 'Work email', 'type' => 'text'],
                        'fee_amount' => [
                            'label' => 'Fee amount',
                            'type' => 'money',
                            'summable' => true,
                        ],
                        'status' => [
                            'label' => 'Status',
                            'column' => 'status_id',
                            'type' => 'enum',
                            'values' => [1 => 'Active', 0 => 'Inactive'],
                        ],
                        'is_vip' => ['label' => 'VIP', 'type' => 'boolean'],
                        'fds_due_date' => [
                            'label' => 'FDS due date',
                            'type' => 'date',
                            'null_sentinels' => ['1970-01-01'],
                        ],
                        'home_suburb' => [
                            'label' => 'Home suburb',
                            'json' => [
                                'column' => 'home_address',
                                'path' => '$.suburb',
                            ],
                            'type' => 'text',
                        ],
                        'home_rent' => [
                            'label' => 'Home rent',
                            'json' => [
                                'column' => 'home_address',
                                'path' => '$.rent',
                            ],
                            'type' => 'money',
                        ],
                    ],
                    'relations' => [
                        'adviser' => [
                            'label' => 'Their adviser',
                            'entity' => 'users',
                            'type' => 'belongs_to',
                            'foreign_key' => 'user_id',
                            'owner_key' => 'id',
                            'zero_is_null' => true,
                        ],
                        'referrer' => [
                            'label' => 'Referred by',
                            'entity' => 'users',
                            'type' => 'belongs_to',
                            'foreign_key' => 'referrer_id',
                            'owner_key' => 'id',
                        ],
                        'notes' => [
                            'label' => 'Their notes',
                            'entity' => 'client_notes',
                            'type' => 'has_many',
                            'foreign_key' => 'client_id',
                            'local_key' => 'id',
                        ],
                    ],
                ],
                'users' => [
                    'label' => 'Adviser',
                    'plural' => 'Advisers',
                    'table' => 'users',
                    'fields' => [
                        'name' => ['label' => 'Name', 'type' => 'text'],
                    ],
                    'relations' => [
                        'team' => [
                            'label' => 'Their team',
                            'entity' => 'teams',
                            'type' => 'belongs_to',
                            'foreign_key' => 'team_id',
                        ],
                    ],
                ],
                'teams' => [
                    'label' => 'Team',
                    'table' => 'teams',
                    'fields' => [
                        'name' => ['label' => 'Name', 'type' => 'text'],
                    ],
                ],
                'client_notes' => [
                    'label' => 'Note',
                    'table' => 'client_notes',
                    'fields' => [
                        'body' => ['label' => 'Body', 'type' => 'text'],
                        'created_at' => [
                            'label' => 'Created',
                            'type' => 'datetime',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function createSchema(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('team_id')->nullable();
            $table->softDeletes();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->increments('id');
            $table->string('firstname');
            $table->string('surname');
            $table->string('home_email')->nullable();
            $table->string('work_email')->nullable();
            $table->decimal('fee_amount', 12, 2)->nullable();
            $table->integer('status_id')->default(1);
            $table->boolean('is_vip')->default(0);
            $table->date('fds_due_date')->nullable();
            $table->text('home_address')->nullable();
            $table->integer('user_id')->default(0);
            $table->integer('referrer_id')->nullable();
            $table->softDeletes();
        });

        Schema::create('client_notes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->string('body');
            $table->dateTime('created_at')->nullable();
        });
    }

    private function seedRows(): void
    {
        DB::table('teams')->insert([
            ['id' => 1, 'name' => 'North'],
            ['id' => 2, 'name' => 'South'],
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Ada', 'team_id' => 1, 'deleted_at' => null],
            ['id' => 2, 'name' => 'Bo', 'team_id' => 2, 'deleted_at' => null],
            // A trashed adviser: joined rows must survive, the name must not.
            [
                'id' => 3,
                'name' => 'Gone',
                'team_id' => 1,
                'deleted_at' => '2026-01-01 00:00:00',
            ],
        ]);

        DB::table('clients')->insert([
            [
                'id' => 1,
                'firstname' => 'Alice',
                'surname' => 'Anderson',
                'home_email' => 'alice@example.com',
                'work_email' => null,
                'fee_amount' => 100.00,
                'status_id' => 1,
                'is_vip' => 1,
                'fds_due_date' => '2026-02-15',
                'home_address' => '{"suburb": "Bondi", "rent": "450.50"}',
                'user_id' => 1,
                'referrer_id' => 2,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'firstname' => 'Bob',
                'surname' => 'Brown',
                'home_email' => null,
                'work_email' => 'bob@work.example',
                'fee_amount' => 250.00,
                'status_id' => 1,
                'is_vip' => 0,
                // The sentinel: means "never set".
                'fds_due_date' => '1970-01-01',
                'home_address' => '{"suburb": "Manly"}',
                'user_id' => 1,
                'referrer_id' => null,
                'deleted_at' => null,
            ],
            [
                'id' => 3,
                'firstname' => 'Carol',
                'surname' => 'Clark',
                'home_email' => null,
                'work_email' => null,
                'fee_amount' => 75.50,
                'status_id' => 0,
                'is_vip' => 0,
                'fds_due_date' => null,
                'home_address' => null,
                // No adviser: a zero foreign key, not a null one.
                'user_id' => 0,
                'referrer_id' => null,
                'deleted_at' => null,
            ],
            [
                'id' => 4,
                'firstname' => 'Dan',
                'surname' => 'Davis',
                'home_email' => 'dan@example.com',
                'work_email' => null,
                'fee_amount' => 500.00,
                'status_id' => 1,
                'is_vip' => 0,
                'fds_due_date' => '2026-03-01',
                'home_address' => null,
                'user_id' => 2,
                'referrer_id' => null,
                'deleted_at' => null,
            ],
            [
                // Soft deleted: must never appear.
                'id' => 5,
                'firstname' => 'Erin',
                'surname' => 'Evans',
                'home_email' => null,
                'work_email' => null,
                'referrer_id' => null,
                'fee_amount' => 9999.00,
                'status_id' => 1,
                'is_vip' => 0,
                'fds_due_date' => null,
                'home_address' => null,
                'user_id' => 1,
                'referrer_id' => null,
                'deleted_at' => '2026-01-01 00:00:00',
            ],
        ]);

        DB::table('client_notes')->insert([
            [
                'id' => 1,
                'client_id' => 1,
                'body' => 'Called about super',
                'created_at' => '2026-02-10 09:14:00',
            ],
            [
                'id' => 2,
                'client_id' => 1,
                'body' => 'Sent FDS',
                'created_at' => '2026-02-11 16:02:00',
            ],
            [
                'id' => 3,
                'client_id' => 2,
                'body' => 'Left voicemail',
                'created_at' => '2026-01-05 11:00:00',
            ],
        ]);
    }

    /**
     * @return QueryCompiler
     */
    private function compiler(): QueryCompiler
    {
        return $this->app->make(QueryCompiler::class);
    }

    /**
     * Run a definition and return the rows.
     *
     * @param array $definition
     * @param array $parameters
     * @param int|null $limit
     * @param int $offset
     * @return array
     */
    private function runReport(
        array $definition,
        array $parameters = [],
        $limit = null,
        int $offset = 0
    ): array {
        return $this->compiler()->execute(
            $definition + ['schema_version' => 2],
            $parameters,
            $limit,
            $offset
        );
    }

    /**
     * Assert a definition is rejected, and return the exception.
     *
     * @param array $definition
     * @param array $parameters
     * @return SemanticException
     */
    private function rejectReport(array $definition, array $parameters = []): SemanticException
    {
        try {
            $this->runReport($definition, $parameters);
        } catch (SemanticException $e) {
            return $e;
        }

        $this->fail('Expected the definition to be rejected');
    }

    /* --------------------------------------------------------------- */

    /** @test */
    public function it_keys_every_row_by_the_field_path_it_was_asked_for()
    {
        $result = $this->runReport([
            'entity' => 'clients',
            'fields' => [
                ['field' => 'firstname'],
                ['field' => 'adviser.name'],
                ['field' => 'adviser.team.name'],
            ],
            'sort' => [['field' => 'firstname', 'dir' => 'asc']],
        ]);

        $this->assertSame(
            ['firstname', 'adviser.name', 'adviser.team.name'],
            array_keys($result['data'][0])
        );

        $this->assertSame(
            [
                'firstname' => 'Alice',
                'adviser.name' => 'Ada',
                'adviser.team.name' => 'North',
            ],
            $result['data'][0]
        );

        // Soft-deleted Erin is gone; the other four are present.
        $this->assertCount(4, $result['data']);
        $this->assertSame(4, $result['total']);
    }

    /** @test */
    public function it_reaches_the_same_entity_twice_through_different_relations()
    {
        $result = $this->runReport([
            'entity' => 'clients',
            'fields' => [
                ['field' => 'firstname'],
                ['field' => 'adviser.name'],
                ['field' => 'referrer.name'],
            ],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'firstname', 'operator' => 'equals', 'value' => 'Alice'],
                ],
            ],
        ]);

        $this->assertSame(
            [
                'firstname' => 'Alice',
                'adviser.name' => 'Ada',
                'referrer.name' => 'Bo',
            ],
            $result['data'][0]
        );
    }

    /** @test */
    public function it_joins_a_relation_that_only_a_filter_or_a_sort_uses()
    {
        // Nothing selects adviser.name - the join has to be registered by
        // the filter and by the sort, or the SQL references a missing alias.
        $result = $this->runReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'adviser.name', 'operator' => 'equals', 'value' => 'Ada'],
                ],
            ],
            'sort' => [['field' => 'surname', 'dir' => 'asc']],
        ]);

        $this->assertSame(
            ['Alice', 'Bob'],
            array_column($result['data'], 'firstname')
        );

        $sortOnly = $this->runReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'sort' => [['field' => 'adviser.team.name', 'dir' => 'desc']],
        ]);

        $this->assertCount(4, $sortOnly['data']);
    }

    /** @test */
    public function it_rejects_unknown_entities_fields_and_relations()
    {
        $unknownEntity = $this->rejectReport([
            'entity' => 'invoices',
            'fields' => [['field' => 'id']],
        ]);

        $this->assertSame(422, $unknownEntity->status());
        $this->assertStringContainsString(
            'Unknown entity [invoices]',
            $unknownEntity->getMessage()
        );

        $unknownField = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [['field' => 'salary']],
        ]);

        $this->assertStringContainsString(
            'Unknown field [salary]',
            $unknownField->getMessage()
        );
        $this->assertSame('salary', $unknownField->errors()[0]['path']);

        $unknownRelation = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [['field' => 'spouse.name']],
        ]);

        $this->assertStringContainsString(
            'Unknown relation [spouse]',
            $unknownRelation->getMessage()
        );
        $this->assertSame('spouse.name', $unknownRelation->errors()[0]['path']);

        // A column that exists in the table but is not published stays
        // unreachable - the registry is the allowlist, not the schema.
        $notPublished = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [['field' => 'user_id']],
        ]);

        $this->assertStringContainsString(
            'Unknown field [user_id]',
            $notPublished->getMessage()
        );
    }

    /** @test */
    public function it_rejects_operators_that_do_not_suit_the_field_type()
    {
        // `contains` is a text operator.
        $wrongType = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'fee_amount', 'operator' => 'contains', 'value' => '10'],
                ],
            ],
        ]);

        $this->assertSame(422, $wrongType->status());
        $this->assertStringContainsString(
            'Operator [contains] cannot be used on [fee_amount]',
            $wrongType->getMessage()
        );

        // An operator nobody has heard of must not fall through to equality.
        $unknown = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'firstname', 'operator' => 'like', 'value' => 'A'],
                ],
            ],
        ]);

        $this->assertStringContainsString(
            'Operator [like] cannot be used on [firstname]',
            $unknown->getMessage()
        );

        // An enum value outside the declared set.
        $badEnum = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'status', 'operator' => 'equals', 'value' => '7'],
                ],
            ],
        ]);

        $this->assertStringContainsString(
            '[7] is not a valid value for [Status]',
            $badEnum->getMessage()
        );

        // between wants exactly two values.
        $badBetween = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    [
                        'field' => 'fee_amount',
                        'operator' => 'between',
                        'value' => [100],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString(
            'exactly two values',
            $badBetween->getMessage()
        );
    }

    /** @test */
    public function it_nests_and_and_or_groups()
    {
        // Active clients that have some kind of email address.
        $result = $this->runReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'status', 'operator' => 'equals', 'value' => '1'],
                    [
                        'op' => 'or',
                        'items' => [
                            ['field' => 'home_email', 'operator' => 'not_empty'],
                            ['field' => 'work_email', 'operator' => 'not_empty'],
                        ],
                    ],
                ],
            ],
            'sort' => [['field' => 'firstname']],
        ]);

        $this->assertSame(
            ['Alice', 'Bob', 'Dan'],
            array_column($result['data'], 'firstname')
        );

        // The OR must stay contained: inactive Carol has no email and must
        // not be pulled back in by the group.
        $this->assertNotContains('Carol', array_column($result['data'], 'firstname'));
    }

    /** @test */
    public function it_substitutes_parameters_and_requires_the_mandatory_ones()
    {
        $definition = [
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    [
                        'field' => 'fds_due_date',
                        'operator' => 'between',
                        'param' => 'due_range',
                    ],
                ],
            ],
            'parameters' => [
                [
                    'id' => 'due_range',
                    'label' => 'Due date range',
                    'type' => 'date_range',
                    'required' => true,
                ],
            ],
            'sort' => [['field' => 'firstname']],
        ];

        $result = $this->runReport($definition, [
            'due_range' => ['2026-01-01', '2026-02-28'],
        ]);

        $this->assertSame(
            ['Alice'],
            array_column($result['data'], 'firstname')
        );

        $missing = $this->rejectReport($definition, []);

        $this->assertSame(422, $missing->status());
        $this->assertStringContainsString(
            'The [Due date range] parameter is required',
            $missing->getMessage()
        );
        $this->assertSame(
            'parameters.due_range',
            $missing->errors()[0]['path']
        );

        // A parameter the definition never declared.
        $undeclared = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    [
                        'field' => 'firstname',
                        'operator' => 'equals',
                        'param' => 'ghost',
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString(
            'parameter [ghost], which the report does not declare',
            $undeclared->getMessage()
        );

        // A malformed date range is rejected rather than silently ignored.
        $malformed = $this->rejectReport($definition, ['due_range' => '2026-01-01']);

        $this->assertStringContainsString(
            'two-element [from, to] range',
            $malformed->getMessage()
        );
    }

    /** @test */
    public function an_optional_parameter_with_no_value_drops_its_condition()
    {
        $definition = [
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    [
                        'field' => 'firstname',
                        'operator' => 'equals',
                        'param' => 'who',
                    ],
                ],
            ],
            'parameters' => [
                ['id' => 'who', 'label' => 'Who', 'type' => 'text'],
            ],
        ];

        $unfiltered = $this->runReport($definition, []);
        $filtered = $this->runReport($definition, ['who' => 'Alice']);

        $this->assertCount(4, $unfiltered['data']);
        $this->assertCount(1, $filtered['data']);
    }

    /** @test */
    public function it_aggregates_and_validates_the_group_by()
    {
        $result = $this->runReport([
            'entity' => 'clients',
            'fields' => [
                ['field' => 'adviser.name'],
                ['agg' => 'sum', 'field' => 'fee_amount', 'label' => 'Total fees'],
                ['agg' => 'count', 'field' => 'firstname', 'label' => 'Clients'],
            ],
            'groupBy' => ['adviser.name'],
            'sort' => [['field' => 'Total fees', 'dir' => 'desc']],
        ]);

        $this->assertSame(
            ['adviser.name', 'Total fees', 'Clients'],
            array_keys($result['data'][0])
        );

        // Ada has Alice (100) + Bob (250); Bo has Dan (500); Carol has none.
        $this->assertEquals(500, $result['data'][0]['Total fees']);
        $this->assertSame('Bo', $result['data'][0]['adviser.name']);
        $this->assertEquals(350, $result['data'][1]['Total fees']);
        $this->assertSame(2, $result['data'][1]['Clients']);

        // Three groups: Ada, Bo and the unadvised (null) group.
        $this->assertSame(3, $result['total']);
    }

    /** @test */
    public function it_infers_the_group_by_when_it_is_omitted()
    {
        $inferred = $this->runReport([
            'entity' => 'clients',
            'fields' => [
                ['field' => 'adviser.name'],
                ['agg' => 'sum', 'field' => 'fee_amount', 'label' => 'Total fees'],
            ],
        ]);

        $this->assertSame(3, $inferred['total']);
    }

    /** @test */
    public function it_rejects_ungrouped_fields_and_impossible_aggregates()
    {
        $ungrouped = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [
                ['field' => 'firstname'],
                ['field' => 'adviser.name'],
                ['agg' => 'sum', 'field' => 'fee_amount', 'label' => 'Total fees'],
            ],
            'groupBy' => ['adviser.name'],
        ]);

        $this->assertSame(422, $ungrouped->status());
        $this->assertStringContainsString(
            'Every non-aggregated field must be grouped: firstname',
            $ungrouped->getMessage()
        );
        $this->assertSame('firstname', $ungrouped->errors()[0]['path']);

        $notSummable = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [
                ['agg' => 'sum', 'field' => 'firstname', 'label' => 'Nonsense'],
            ],
        ]);

        $this->assertStringContainsString(
            'cannot be aggregated with sum',
            $notSummable->getMessage()
        );

        $unknownAgg = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [['agg' => 'median', 'field' => 'fee_amount']],
        ]);

        $this->assertStringContainsString(
            'Unknown aggregate [median]',
            $unknownAgg->getMessage()
        );

        // count works on any type, including text.
        $counted = $this->runReport([
            'entity' => 'clients',
            'fields' => [
                ['agg' => 'count', 'field' => 'firstname', 'label' => 'How many'],
            ],
        ]);

        $this->assertSame(4, $counted['data'][0]['How many']);
    }

    /** @test */
    public function it_counts_over_a_has_many_relation()
    {
        $result = $this->runReport([
            'entity' => 'clients',
            'fields' => [
                ['field' => 'firstname'],
                ['agg' => 'count', 'field' => 'notes.body', 'label' => 'Notes'],
            ],
            'groupBy' => ['firstname'],
            'sort' => [['field' => 'firstname']],
        ]);

        $counts = array_column($result['data'], 'Notes', 'firstname');

        $this->assertSame(2, $counts['Alice']);
        $this->assertSame(1, $counts['Bob']);
        // A LEFT JOIN keeps the client with no notes, counted as zero.
        $this->assertSame(0, $counts['Carol']);
    }

    /** @test */
    public function it_treats_declared_sentinels_as_null()
    {
        $rows = $this->runReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname'], ['field' => 'fds_due_date']],
            'sort' => [['field' => 'firstname']],
        ])['data'];

        $dates = array_column($rows, 'fds_due_date', 'firstname');

        $this->assertSame('2026-02-15', $dates['Alice']);
        // Stored as 1970-01-01, which this table uses to mean "never set".
        $this->assertNull($dates['Bob']);
        $this->assertNull($dates['Carol']);

        // ... and the same value counts as empty when filtering.
        $empty = $this->runReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'fds_due_date', 'operator' => 'is_empty'],
                ],
            ],
            'sort' => [['field' => 'firstname']],
        ]);

        $this->assertSame(
            ['Bob', 'Carol'],
            array_column($empty['data'], 'firstname')
        );

        $notEmpty = $this->runReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'fds_due_date', 'operator' => 'not_empty'],
                ],
            ],
            'sort' => [['field' => 'firstname']],
        ]);

        $this->assertSame(
            ['Alice', 'Dan'],
            array_column($notEmpty['data'], 'firstname')
        );
    }

    /** @test */
    public function it_excludes_soft_deleted_rows_without_dropping_their_owners()
    {
        // Erin is soft deleted on clients.
        $names = array_column(
            $this->runReport([
                'entity' => 'clients',
                'fields' => [['field' => 'firstname']],
            ])['data'],
            'firstname'
        );

        $this->assertNotContains('Erin', $names);

        // Adviser 3 is soft deleted. Point a client at them and the client
        // must still appear, with a null adviser - the join filter belongs
        // in the ON clause, not the WHERE.
        DB::table('clients')->where('id', 4)->update(['user_id' => 3]);

        $rows = $this->runReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname'], ['field' => 'adviser.name']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'firstname', 'operator' => 'equals', 'value' => 'Dan'],
                ],
            ],
        ])['data'];

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['adviser.name']);
    }

    /** @test */
    public function a_zero_foreign_key_joins_to_nothing()
    {
        $compiled = $this->compiler()->compile([
            'schema_version' => 2,
            'entity' => 'clients',
            'fields' => [['field' => 'firstname'], ['field' => 'adviser.name']],
        ]);

        $sql = $compiled['query']->toSql();

        // The zero-foreign-key guard rides in the ON clause.
        $this->assertStringContainsString(
            'left join "users" as "rel_adviser" on "clients"."user_id" = "rel_adviser"."id" and "clients"."user_id" <> 0',
            $sql
        );

        // Carol's user_id is 0 and there is no user 0, but the guard is what
        // stops a future user 0 from being joined in.
        $rows = $this->runReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname'], ['field' => 'adviser.name']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'firstname', 'operator' => 'equals', 'value' => 'Carol'],
                ],
            ],
        ])['data'];

        $this->assertNull($rows[0]['adviser.name']);

        // The relation without zero_is_null carries no such condition.
        $referrerSql = $this->compiler()
            ->compile([
                'schema_version' => 2,
                'entity' => 'clients',
                'fields' => [['field' => 'referrer.name']],
            ])['query']
            ->toSql();

        $this->assertStringContainsString(
            'left join "users" as "rel_referrer" on "clients"."referrer_id" = "rel_referrer"."id"',
            $referrerSql
        );
        $this->assertStringNotContainsString('"referrer_id" <> 0', $referrerSql);
    }

    /** @test */
    public function it_extracts_json_fields_with_a_cast_for_numeric_types()
    {
        // MySQL-only SQL: asserted on the statement, not executed, because
        // SQLite has no JSON_UNQUOTE.
        $compiled = $this->compiler()->compile([
            'schema_version' => 2,
            'entity' => 'clients',
            'fields' => [
                ['field' => 'home_suburb'],
                ['agg' => 'sum', 'field' => 'home_rent', 'label' => 'Rent'],
            ],
            'groupBy' => ['home_suburb'],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'home_rent', 'operator' => 'gt', 'value' => 100],
                    ['field' => 'home_suburb', 'operator' => 'is_empty'],
                ],
            ],
        ]);

        $sql = $compiled['query']->toSql();

        // Text: unquoted extraction, no cast.
        $this->assertStringContainsString(
            'JSON_UNQUOTE(JSON_EXTRACT("clients"."home_address", \'$.suburb\')) as "c0"',
            $sql
        );

        // Money: cast so comparison and SUM are numeric, not lexical.
        $this->assertStringContainsString(
            'SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT("clients"."home_address", \'$.rent\')) AS DECIMAL(14,2))) as "c1"',
            $sql
        );

        $this->assertStringContainsString(
            'CAST(JSON_UNQUOTE(JSON_EXTRACT("clients"."home_address", \'$.rent\')) AS DECIMAL(14,2)) > ?',
            $sql
        );

        // Emptiness tests use the *uncast* value: CAST('' AS DECIMAL) is 0,
        // which would make is_empty mean "equals zero".
        $suburb =
            'JSON_UNQUOTE(JSON_EXTRACT("clients"."home_address", \'$.suburb\'))';

        $this->assertStringContainsString(
            "({$suburb} is null or {$suburb} = '' or {$suburb} = ?)",
            $sql
        );

        // A JSON null extracts as the string 'null' and is treated as empty.
        $this->assertContains('null', $compiled['outputs'][0]['sentinels']);

        $this->assertStringContainsString(
            'group by JSON_UNQUOTE(JSON_EXTRACT("clients"."home_address", \'$.suburb\'))',
            $sql
        );
    }

    /** @test */
    public function it_escapes_like_wildcards_in_a_contains_filter()
    {
        DB::table('clients')
            ->where('id', 3)
            ->update(['surname' => '100% Clark']);

        $result = $this->runReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'surname', 'operator' => 'contains', 'value' => '100%'],
                ],
            ],
        ]);

        // Without escaping, `100%` would match every surname beginning 100.
        $this->assertCount(1, $result['data']);
        $this->assertSame('Carol', $result['data'][0]['firstname']);
    }

    /** @test */
    public function it_widens_a_date_filter_on_a_datetime_field_to_the_whole_day()
    {
        $result = $this->runReport([
            'entity' => 'client_notes',
            'fields' => [['field' => 'body']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    [
                        'field' => 'created_at',
                        'operator' => 'equals',
                        'value' => '2026-02-10',
                    ],
                ],
            ],
        ]);

        // The note is stamped 09:14 on the day asked for.
        $this->assertCount(1, $result['data']);
        $this->assertSame('Called about super', $result['data'][0]['body']);
    }

    /** @test */
    public function it_serialises_dates_and_numbers_predictably()
    {
        $rows = $this->runReport([
            'entity' => 'client_notes',
            'fields' => [['field' => 'body'], ['field' => 'created_at']],
            'sort' => [['field' => 'created_at', 'dir' => 'asc']],
        ])['data'];

        $this->assertSame('2026-01-05 11:00:00', $rows[0]['created_at']);

        $client = $this->runReport([
            'entity' => 'clients',
            'fields' => [['field' => 'fee_amount'], ['field' => 'fds_due_date']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'firstname', 'operator' => 'equals', 'value' => 'Alice'],
                ],
            ],
        ])['data'][0];

        // Dates are Y-m-d, and money comes back as a number, unformatted.
        $this->assertSame('2026-02-15', $client['fds_due_date']);
        $this->assertIsNumeric($client['fee_amount']);
        $this->assertEquals(100, $client['fee_amount']);
    }

    /** @test */
    public function it_filters_booleans_and_enums()
    {
        $vip = $this->runReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [['field' => 'is_vip', 'operator' => 'is_true']],
            ],
        ]);

        $this->assertSame(
            ['Alice'],
            array_column($vip['data'], 'firstname')
        );

        $inactive = $this->runReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => [
                'op' => 'and',
                'items' => [
                    ['field' => 'status', 'operator' => 'in', 'value' => ['0']],
                ],
            ],
        ]);

        $this->assertSame(
            ['Carol'],
            array_column($inactive['data'], 'firstname')
        );
    }

    /** @test */
    public function it_paginates_with_a_default_and_a_ceiling()
    {
        $page = $this->runReport(
            [
                'entity' => 'clients',
                'fields' => [['field' => 'firstname']],
                'sort' => [['field' => 'firstname']],
            ],
            [],
            2,
            1
        );

        $this->assertSame(
            ['Bob', 'Carol'],
            array_column($page['data'], 'firstname')
        );

        // total ignores limit/offset.
        $this->assertSame(4, $page['total']);

        $definition = [
            'schema_version' => 2,
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
        ];

        $this->assertSame(
            QueryCompiler::DEFAULT_LIMIT,
            $this->compiler()->compile($definition)['query']->limit
        );

        $this->assertSame(
            QueryCompiler::MAX_LIMIT,
            $this->compiler()->compile($definition, [], 999999)['query']->limit
        );
    }

    /** @test */
    public function it_rejects_a_sort_that_cannot_be_answered()
    {
        $bad = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [
                ['field' => 'adviser.name'],
                ['agg' => 'sum', 'field' => 'fee_amount', 'label' => 'Total fees'],
            ],
            'groupBy' => ['adviser.name'],
            'sort' => [['field' => 'surname']],
        ]);

        $this->assertStringContainsString(
            '[surname] cannot be sorted on',
            $bad->getMessage()
        );

        $badDirection = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'sort' => [['field' => 'firstname', 'dir' => 'sideways']],
        ]);

        $this->assertStringContainsString(
            'Unknown sort direction [sideways]',
            $badDirection->getMessage()
        );
    }

    /** @test */
    public function it_requires_a_selection_and_an_entity()
    {
        $noFields = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [],
        ]);

        $this->assertStringContainsString(
            'must select at least one field',
            $noFields->getMessage()
        );

        $noEntity = $this->rejectReport([
            'fields' => [['field' => 'firstname']],
        ]);

        $this->assertStringContainsString(
            'must name an entity',
            $noEntity->getMessage()
        );
    }

    /** @test */
    public function it_rejects_duplicate_column_keys()
    {
        $duplicate = $this->rejectReport([
            'entity' => 'clients',
            'fields' => [
                ['field' => 'firstname'],
                ['field' => 'surname', 'label' => 'firstname'],
            ],
        ]);

        $this->assertStringContainsString(
            'Duplicate column [firstname]',
            $duplicate->getMessage()
        );
    }

    /** @test */
    public function the_semantic_model_endpoint_payload_is_client_safe()
    {
        $payload = $this->app->make(SemanticModel::class)->toClientPayload();
        $encoded = json_encode($payload);

        $this->assertArrayHasKey('clients', (array) $payload['entities']);
        $this->assertStringNotContainsString('status_id', $encoded);
        $this->assertStringNotContainsString('home_address', $encoded);
        $this->assertStringNotContainsString('user_id', $encoded);
    }
}
