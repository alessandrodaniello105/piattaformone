<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fic_accounts', function (Blueprint $table) {
            $table->id();
            
            // Account name
            $table->string('name')->nullable()->comment('Account name');
            
            // Fatture in Cloud Company Information
            $table->unsignedBigInteger('company_id')->unique()->index()->comment('Fatture in Cloud company ID');
            $table->string('company_name')->nullable()->comment('Company name from FIC');
            $table->string('company_email')->nullable()->comment('Company email from FIC');
            
            // OAuth Credentials (encrypted)
            // Note: These should be encrypted using Laravel's encryption
            // See: https://laravel.com/docs/encryption
            $table->text('access_token')->nullable()->comment('Encrypted OAuth access token');
            $table->text('refresh_token')->nullable()->comment('Encrypted OAuth refresh token');
            $table->timestamp('token_expires_at')->nullable()->comment('When the access token expires');
            $table->timestamp('token_refreshed_at')->nullable()->comment('Last time the token was refreshed');
            
            // Account Status
            $table->string('status')->default('active')->index()->comment('Status: active, suspended, revoked');
            $table->text('status_note')->nullable()->comment('Note about account status');
            
            // Webhook Configuration
            $table->string('webhook_url')->nullable()->comment('Webhook URL configured in FIC for this account');
            $table->boolean('webhook_enabled')->default(true)->comment('Whether webhooks are enabled for this account');
            $table->timestamp('webhook_verified_at')->nullable()->comment('When webhook subscription was verified');
            
            // Metadata
            $table->json('metadata')->nullable()->comment('Additional metadata (subscription info, features, etc.)');
            $table->json('settings')->nullable()->comment('Account-specific settings');
            
            // Multi-tenant support (for future integration with Laravel multi-tenancy packages)
            $table->string('tenant_id')->nullable()->index()->comment('Tenant identifier for multi-tenancy support');
            
            // Timestamps
            $table->timestamp('connected_at')->nullable()->comment('When the account was first connected');
            $table->timestamp('last_sync_at')->nullable()->comment('Last successful API sync');
            $table->timestamps();
            
            // Indexes
            $table->index(['status', 'created_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fic_accounts');
    }
};
