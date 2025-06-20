<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProposalTemplatesTable extends Migration
{
    /**
     * Run the migrations.
     * Creates proposal templates table with backward compatibility
     *
     * @return void
     */
    public function up()
    {
        Schema::create('proposal_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('variables')->nullable(); // Custom variables for this template
            $table->json('styling')->nullable(); // Custom styling options
            $table->boolean('is_default')->default(false);
            $table->foreignId('branding_profile_id')->nullable()->constrained('branding_profiles')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('is_default');
            $table->index(['deleted_at', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('proposal_templates');
    }
}