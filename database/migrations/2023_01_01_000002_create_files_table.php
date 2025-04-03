<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFilesTable extends Migration
{
    /**
     * Run the migration.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('files')) {
            Schema::create('files', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('fileable_id')->nullable();
                $table->string('fileable_field')->nullable();
                $table->string('fileable_type')->nullable();
                $table->string('file_path');
                $table->string('file_name')->nullable();
                $table->string('file_extension')->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('file_title')->nullable();
                $table->text('file_description')->nullable();
                $table->integer('sort_order')->nullable();
                $table->timestamps();
                
                // Add index for faster lookups
                $table->index(['fileable_id', 'fileable_type']);
            });
        } else {
            // If the table exists, check if each column exists and add it if it doesn't
            Schema::table('files', function (Blueprint $table) {
                if (!Schema::hasColumn('files', 'fileable_id')) {
                    $table->unsignedBigInteger('fileable_id')->nullable();
                }
                
                if (!Schema::hasColumn('files', 'fileable_field')) {
                    $table->string('fileable_field')->nullable();
                }
                
                if (!Schema::hasColumn('files', 'fileable_type')) {
                    $table->string('fileable_type')->nullable();
                }
                
                if (!Schema::hasColumn('files', 'file_path')) {
                    $table->string('file_path');
                }
                
                if (!Schema::hasColumn('files', 'file_name')) {
                    $table->string('file_name')->nullable();
                }
                
                if (!Schema::hasColumn('files', 'file_extension')) {
                    $table->string('file_extension')->nullable();
                }
                
                if (!Schema::hasColumn('files', 'file_size')) {
                    $table->unsignedBigInteger('file_size')->nullable();
                }
                
                if (!Schema::hasColumn('files', 'file_title')) {
                    $table->string('file_title')->nullable();
                }
                
                if (!Schema::hasColumn('files', 'file_description')) {
                    $table->text('file_description')->nullable();
                }
                
                if (!Schema::hasColumn('files', 'sort_order')) {
                    $table->integer('sort_order')->nullable();
                }
                
                // Add index if it doesn't exist
                if (!Schema::hasIndex('files', ['fileable_id', 'fileable_type'])) {
                    $table->index(['fileable_id', 'fileable_type']);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // We don't want to drop the table on rollback as it might contain important data
        // If you want to drop the table, uncomment the line below
        // Schema::dropIfExists('files');
    }
}
