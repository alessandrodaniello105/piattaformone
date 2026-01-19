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
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            
            // Event information
            $table->string('webhook_event')->index()->comment('Type of webhook event (e.g., invoice.created, payment.received)');
            $table->string('event_type')->nullable()->index()->comment('General event category');
            
            // Payload and data
            $table->json('payload')->comment('Complete webhook payload as JSON');
            $table->json('headers')->nullable()->comment('HTTP headers from the webhook request');
            
            // Security and verification
            $table->string('signature')->nullable()->index()->comment('JWT signature for verification');
            $table->string('ip_address', 45)->nullable()->index()->comment('IP address of the webhook sender');
            
            // Status tracking
            $table->string('status')->default('received')->index()->comment('Status: received, processing, processed, error');
            $table->text('error_message')->nullable()->comment('Error message if processing failed');
            
            // Response information
            $table->integer('response_code')->nullable()->comment('HTTP response code sent back');
            $table->text('response_body')->nullable()->comment('Response body sent back');
            
            // Timestamps
            $table->timestamp('received_at')->useCurrent()->index()->comment('When the webhook was received');
            $table->timestamp('processed_at')->nullable()->index()->comment('When the webhook was fully processed');
            
            // Company/Account reference (for future multi-tenant support)
            $table->unsignedBigInteger('company_id')->nullable()->index()->comment('Fatture in Cloud company ID');
            
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['status', 'received_at']);
            $table->index(['webhook_event', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
