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
        Schema::create('fic_clients', function (Blueprint $table) {
            $table->id();
            
            // Foreign key to fic_accounts
            $table->foreignId('fic_account_id')
                ->constrained('fic_accounts')
                ->onDelete('cascade')
                ->comment('Reference to FIC account');
            
            // FIC client identifier
            $table->unsignedBigInteger('fic_client_id')->comment('FIC client ID');
            
            // Client key fields
            $table->string('name')->nullable()->comment('Client name');
            $table->string('code')->nullable()->comment('Client code');
            
            // FIC timestamps
            $table->timestamp('fic_created_at')->nullable()->comment('Creation date from FIC');
            $table->timestamp('fic_updated_at')->nullable()->comment('Last update date from FIC');
            
            // Raw JSON data from FIC API
            $table->json('raw')->nullable()->comment('Raw JSON data from FIC API');
            
            // Timestamps
            $table->timestamps();
            
            // Indexes
            $table->index('fic_account_id');
            $table->index('fic_client_id');
            $table->index(['fic_account_id', 'fic_client_id']);
            $table->index('fic_created_at');
            $table->unique(['fic_account_id', 'fic_client_id'], 'fic_clients_account_client_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fic_clients');
    }
};
