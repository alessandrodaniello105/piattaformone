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
        Schema::create('fic_subscriptions', function (Blueprint $table) {
            $table->id();
            
            // Foreign key to fic_accounts
            $table->foreignId('fic_account_id')
                ->constrained('fic_accounts')
                ->onDelete('cascade')
                ->comment('Reference to FIC account');
            
            // FIC subscription identifier
            $table->string('fic_subscription_id')->unique()->comment('FIC subscription ID');
            
            // Event group (e.g., 'entity', 'issued_documents')
            $table->string('event_group')->comment('Event group name');
            
            // Webhook secret (encrypted)
            $table->text('webhook_secret')->nullable()->comment('Encrypted webhook secret for signature verification');
            
            // Subscription expiration
            $table->timestamp('expires_at')->nullable()->comment('When the subscription expires');
            
            // Active status
            $table->boolean('is_active')->default(true)->comment('Whether the subscription is active');
            
            // Timestamps
            $table->timestamps();
            
            // Indexes
            $table->index('fic_account_id');
            $table->index('event_group');
            $table->index('expires_at');
            $table->index('is_active');
            $table->index(['fic_account_id', 'event_group']);
            $table->index(['is_active', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fic_subscriptions');
    }
};
