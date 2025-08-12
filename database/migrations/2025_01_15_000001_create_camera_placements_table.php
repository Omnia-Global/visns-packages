<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCameraPlacementsTable extends Migration
{
    /**
     * Run the migrations.
     * Creates camera_placements table for storing camera layout data
     *
     * @return void
     */
    public function up()
    {
        Schema::create('camera_placements', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Display name for the placement
            $table->text('description')->nullable(); // Optional description
            $table->string('project_name'); // Project name (from UI input)
            $table->string('version', 50)->default('1.0'); // Version for compatibility
            $table->enum('mode', ['image', 'map'])->default('image'); // Placement mode
            $table->json('image_data')->nullable(); // Base64 image and metadata
            $table->string('image_name')->nullable(); // Original image filename
            $table->json('cameras_data'); // Array of camera positions and data
            $table->unsignedBigInteger('user_id'); // Creator user ID
            $table->timestamps();
            $table->softDeletes();

            // Foreign key constraint
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Indexes for performance
            $table->index('user_id');
            $table->index('mode');
            $table->index('project_name');
            $table->index(['deleted_at', 'name']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('camera_placements');
    }
}