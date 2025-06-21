<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class ExpandProposalTemplateSectionsTypes extends Migration
{
    /**
     * Run the migrations.
     * Expands the section_type enum to include all section types used in ProposalAssemblyService
     *
     * @return void
     */
    public function up()
    {
        // For MySQL, we need to modify the enum by recreating the column
        DB::statement("ALTER TABLE proposal_template_sections MODIFY COLUMN section_type ENUM(
            'cover_page', 
            'toc', 
            'content', 
            'quote_items', 
            'terms',
            'terms_conditions',
            'review_log',
            'overview',
            'acceptance',
            'payment_terms',
            'agreement_signature'
        )");
    }

    /**
     * Reverse the migrations.
     * Note: This will revert to original enum values and may cause data loss
     * if new section types are in use
     *
     * @return void
     */
    public function down()
    {
        // Revert to original enum values
        // WARNING: This may cause data loss if new section types are in use
        DB::statement("ALTER TABLE proposal_template_sections MODIFY COLUMN section_type ENUM(
            'cover_page', 
            'toc', 
            'content', 
            'quote_items', 
            'terms'
        )");
    }
}