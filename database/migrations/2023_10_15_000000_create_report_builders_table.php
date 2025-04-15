<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('report_builders', function (Blueprint $table) {
            $table->id();
            $table->string('label')->comment('Name of the report');
            $table->json('detail')->comment('JSON configuration for the report builder settings');
            $table->foreignId('user_id')->nullable()->comment('User who created the report');
            $table->boolean('is_public')->default(false)->comment('Whether the report is public or private');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('report_builders');
    }
};
