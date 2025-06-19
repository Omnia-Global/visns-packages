<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProposalTemplateSectionsTable extends Migration
{
    /**
     * Run the migrations.
     * Creates proposal template sections table with backward compatibility
     *
     * @return void
     */
    public function up()
    {
        Schema::create('proposal_template_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('proposal_templates')->onDelete('cascade');
            $table->enum('section_type', [
                'cover_page', 
                'toc', 
                'content', 
                'quote_items', 
                'terms'
            ]);
            $table->string('title');
            $table->longText('content')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_dynamic')->default(false); // Whether content is generated dynamically
            $table->json('variables')->nullable(); // Section-specific variables
            $table->json('styling')->nullable(); // Section-specific styling
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['template_id', 'sort_order']);
            $table->index(['template_id', 'section_type']);
            $table->index(['deleted_at', 'template_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('proposal_template_sections');
    }
}