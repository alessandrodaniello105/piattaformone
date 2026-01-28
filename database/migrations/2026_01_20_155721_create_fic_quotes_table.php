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
        Schema::create('fic_quotes', function (Blueprint $table) {
            $table->id();
            
            // Foreign key to fic_accounts
            $table->foreignId('fic_account_id')
                ->constrained('fic_accounts')
                ->onDelete('cascade')
                ->comment('Reference to FIC account');
            
            // FIC quote identifier
            $table->unsignedBigInteger('fic_quote_id')->comment('FIC quote ID');
            
            // Quote key fields
            $table->string('number')->nullable()->comment('Quote number');
            $table->string('status')->nullable()->comment('Quote status');
            $table->decimal('total_gross', 15, 2)->nullable()->comment('Total gross amount');
            $table->date('fic_date')->nullable()->comment('Quote date from FIC');
            $table->timestamp('fic_created_at')->nullable()->comment('Creation date from FIC');
            
            // Raw JSON data from FIC API
            $table->json('raw')->nullable()->comment('Raw JSON data from FIC API');
            
            // Timestamps
            $table->timestamps();
            
            // Indexes
            $table->index('fic_account_id');
            $table->index('fic_quote_id');
            $table->index(['fic_account_id', 'fic_quote_id']);
            $table->index('fic_created_at');
            $table->index('fic_date');
            $table->unique(['fic_account_id', 'fic_quote_id'], 'fic_quotes_account_quote_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fic_quotes');
    }
};
