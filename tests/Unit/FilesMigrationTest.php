<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FilesMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_files_table_with_correct_columns()
    {
        // Run the migration
        include_once __DIR__ . '/../../database/migrations/2023_01_01_000002_create_files_table.php';
        $migration = new \CreateFilesTable();
        $migration->up();

        // Check if the table was created
        $this->assertTrue(Schema::hasTable('files'));

        // Check if all the columns were added
        $this->assertTrue(Schema::hasColumn('files', 'id'));
        $this->assertTrue(Schema::hasColumn('files', 'fileable_id'));
        $this->assertTrue(Schema::hasColumn('files', 'fileable_field'));
        $this->assertTrue(Schema::hasColumn('files', 'fileable_type'));
        $this->assertTrue(Schema::hasColumn('files', 'file_path'));
        $this->assertTrue(Schema::hasColumn('files', 'file_name'));
        $this->assertTrue(Schema::hasColumn('files', 'file_extension'));
        $this->assertTrue(Schema::hasColumn('files', 'file_size'));
        $this->assertTrue(Schema::hasColumn('files', 'file_title'));
        $this->assertTrue(Schema::hasColumn('files', 'file_description'));
        $this->assertTrue(Schema::hasColumn('files', 'sort_order'));
        $this->assertTrue(Schema::hasColumn('files', 'created_at'));
        $this->assertTrue(Schema::hasColumn('files', 'updated_at'));
    }
}
