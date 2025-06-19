<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBrandingProfilesTable extends Migration
{
    /**
     * Run the migrations.
     * Creates branding profiles table with backward compatibility
     *
     * @return void
     */
    public function up()
    {
        Schema::create('branding_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_name');
            $table->string('logo_url')->nullable(); // URL to logo file
            $table->json('colors')->nullable(); // Brand colors (primary, secondary, accent)
            $table->json('fonts')->nullable(); // Font configuration (heading, body)
            $table->json('company_info')->nullable(); // Address, phone, email, website, ABN
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('is_default');
            $table->index(['deleted_at', 'name']);
            $table->index('company_name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('branding_profiles');
    }
}