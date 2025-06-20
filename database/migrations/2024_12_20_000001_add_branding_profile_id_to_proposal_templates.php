<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBrandingProfileIdToProposalTemplates extends Migration
{
    /**
     * Run the migrations.
     * Adds branding_profile_id foreign key to existing proposal_templates table
     *
     * @return void
     */
    public function up()
    {
        Schema::table('proposal_templates', function (Blueprint $table) {
            $table->foreignId('branding_profile_id')
                  ->nullable()
                  ->after('is_default')
                  ->constrained('branding_profiles')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('proposal_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branding_profile_id');
        });
    }
}