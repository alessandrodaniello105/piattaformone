<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add FIC OAuth credentials to teams table for multi-tenant support.
     * Each team can have its own FIC app with different credentials and scopes.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // FIC OAuth App credentials
            $table->string('fic_client_id')->nullable()->after('personal_team');
            // Use text() for client_secret because encrypted cast produces long JSON strings
            $table->text('fic_client_secret')->nullable()->after('fic_client_id');
            $table->text('fic_redirect_uri')->nullable()->after('fic_client_secret');
            
            // OAuth scopes as JSON array (e.g., ["settings.all", "issued_documents.invoices.read"])
            $table->json('fic_scopes')->nullable()->after('fic_redirect_uri');
            
            // Metadata for tracking
            $table->timestamp('fic_configured_at')->nullable()->after('fic_scopes');
            
            // Index for faster lookups
            $table->index('fic_client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['fic_client_id']);
            $table->dropColumn([
                'fic_client_id',
                'fic_client_secret',
                'fic_redirect_uri',
                'fic_scopes',
                'fic_configured_at',
            ]);
        });
    }
};
