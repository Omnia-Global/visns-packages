<?php

namespace Visnsstudio\VisnsPackages\Tests\Platform\ReportSemantics;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Visnsstudio\VisnsPackages\Tests\TestCase;

/**
 * `report-builder:upgrade-legacy` end to end on SQLite: what it writes, what
 * it backs up, and what it leaves alone.
 *
 * SQLite has no JSON_UNQUOTE, so a converted definition that reaches a JSON
 * field fails the compile-and-run check here - which is how the "engine
 * rejected it, nothing written" path is exercised.
 */
class ReportBuilderUpgradeLegacyCommandTest extends TestCase
{
    private string $backupPath;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('visns-packages.report_semantics', [
            'entities' => [
                'clients' => [
                    'table' => 'clients',
                    'soft_deletes' => true,
                    'fields' => [
                        'firstname' => ['type' => 'text'],
                        'surname' => ['type' => 'text'],
                        'home_suburb' => ['type' => 'text', 'json' => ['column' => 'home_address', 'path' => '$.suburb']],
                    ],
                ],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('firstname')->nullable();
            $table->string('surname')->nullable();
            $table->text('home_address')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->runPackageMigration('2023_10_15_000000_create_report_builders_table.php');

        DB::table('clients')->insert([
            ['firstname' => 'Lizette', 'surname' => 'Brown'],
            ['firstname' => 'Ann', 'surname' => 'Lee'],
        ]);

        $this->backupPath = sys_get_temp_dir() . '/rb-upgrade-' . uniqid() . '.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->backupPath);

        parent::tearDown();
    }

    private function report(int $id, array $detail, array $extra = []): void
    {
        DB::table('report_builders')->insert(array_merge([
            'id' => $id,
            'label' => "Report {$id}",
            'detail' => json_encode($detail),
            'user_id' => 7,
            'is_public' => true,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ], $extra));
    }

    private function detail(int $id): array
    {
        return json_decode(DB::table('report_builders')->where('id', $id)->value('detail'), true);
    }

    private function seedReports(): array
    {
        $plain = [
            'mainTable' => 'clients',
            'joins' => [],
            'columns' => [['table' => 'clients', 'column' => 'firstname'], ['table' => 'clients', 'column' => 'id']],
            'filters' => ['operator' => 'AND', 'groups' => [['operator' => 'AND', 'filters' => [
                ['table' => 'clients', 'column' => 'firstname', 'operator' => 'LIKE', 'value' => 'ize'],
            ]]]],
            'sorting' => [['table' => 'clients', 'column' => 'surname', 'direction' => 'DESC']],
        ];
        $json = ['mainTable' => 'clients', 'columns' => [['table' => 'clients', 'column' => 'home_address']]];
        $unknown = ['mainTable' => 'widgets', 'columns' => [['table' => 'widgets', 'column' => 'name']]];
        $v2 = ['schema_version' => 2, 'entity' => 'clients', 'fields' => [['field' => 'surname']]];

        $this->report(1, $plain);
        $this->report(2, $json);
        $this->report(3, $unknown);
        $this->report(4, $v2);
        $this->report(5, $plain, ['deleted_at' => '2025-02-01 00:00:00']);

        return compact('plain', 'json', 'unknown', 'v2');
    }

    public function test_dry_run_reports_everything_and_writes_nothing(): void
    {
        $seeded = $this->seedReports();

        $this->artisan('report-builder:upgrade-legacy', ['--dry-run' => true, '--backup-path' => $this->backupPath])
            ->expectsOutputToContain('#1 Report 1')
            ->expectsOutputToContain('"operator": "contains"')
            ->expectsOutputToContain('[clients.id] is not published')
            ->expectsOutputToContain('failed (the v2 engine rejected')
            ->expectsOutputToContain('skipped (No entity in the semantic model is backed by table [widgets].)')
            ->expectsOutputToContain('Dry run: 1 converted, 2 skipped, 1 failed.')
            ->assertExitCode(0);

        $this->assertSame($seeded['plain'], $this->detail(1));
        $this->assertSame($seeded['json'], $this->detail(2));
        $this->assertFileDoesNotExist($this->backupPath);
    }

    public function test_upgrade_rewrites_convertible_rows_and_backs_up_the_originals(): void
    {
        $seeded = $this->seedReports();

        $this->artisan('report-builder:upgrade-legacy', ['--backup-path' => $this->backupPath])
            ->expectsOutputToContain('Done: 1 converted, 2 skipped, 1 failed.')
            ->assertExitCode(0);

        $this->assertSame([
            'schema_version' => 2,
            'entity' => 'clients',
            'fields' => [['field' => 'firstname']],
            'filters' => ['op' => 'and', 'items' => [['field' => 'firstname', 'operator' => 'contains', 'value' => 'ize']]],
            'sort' => [['field' => 'surname', 'dir' => 'desc']],
        ], $this->detail(1));

        $row = DB::table('report_builders')->where('id', 1)->first();
        $this->assertSame('Report 1', $row->label);
        $this->assertSame(7, (int) $row->user_id);
        $this->assertSame(1, (int) $row->is_public);
        $this->assertNotSame('2025-01-01 00:00:00', $row->updated_at);

        // Rejected by the engine, not convertible, already v2, soft-deleted:
        // all untouched.
        $this->assertSame($seeded['json'], $this->detail(2));
        $this->assertSame($seeded['unknown'], $this->detail(3));
        $this->assertSame($seeded['v2'], $this->detail(4));
        $this->assertSame($seeded['plain'], $this->detail(5));

        $lines = file($this->backupPath, FILE_IGNORE_NEW_LINES);
        $this->assertCount(1, $lines);
        $this->assertSame(
            ['id' => 1, 'label' => 'Report 1', 'detail' => $seeded['plain']],
            json_decode($lines[0], true)
        );

        // The upgraded row now runs through the v2 engine.
        $this->artisan('report-builder:upgrade-legacy', ['--id' => [1]])
            ->expectsOutputToContain('skipped (not a v1 definition')
            ->assertExitCode(0);
    }

    public function test_id_option_limits_the_run(): void
    {
        $seeded = $this->seedReports();

        $this->artisan('report-builder:upgrade-legacy', ['--id' => [3, 4], '--backup-path' => $this->backupPath])
            ->expectsOutputToContain('Done: 0 converted, 2 skipped, 0 failed.')
            ->assertExitCode(0);

        $this->assertSame($seeded['plain'], $this->detail(1));
        $this->assertFileDoesNotExist($this->backupPath);
    }
}
