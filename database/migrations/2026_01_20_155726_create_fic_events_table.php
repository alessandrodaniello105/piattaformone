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
        Schema::create('fic_events', function (Blueprint $table) {
            $table->id();
            
            // Foreign key to fic_accounts
            $table->foreignId('fic_account_id')
                ->constrained('fic_accounts')
                ->onDelete('cascade')
                ->comment('Reference to FIC account');
            
            // Event information
            $table->string('event_type')->comment('CloudEvents type (e.g., it.fattureincloud.webhooks.entities.clients.create)');
            $table->string('resource_type')->comment('Resource type: client, quote, or invoice');
            $table->unsignedBigInteger('fic_resource_id')->comment('FIC resource ID');
            $table->timestamp('occurred_at')->comment('When the event occurred (from CloudEvents ce-time)');
            
            // Raw payload JSON
            $table->json('payload')->nullable()->comment('Full event payload JSON');
            
            // Timestamps
            $table->timestamps();
            
            // Indexes
            $table->index('fic_account_id');
            $table->index('event_type');
            $table->index('resource_type');
            $table->index('occurred_at');
            $table->index(['fic_account_id', 'resource_type', 'occurred_at']);
            $table->index(['resource_type', 'fic_resource_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fic_events');
    }
};
