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
        Schema::create('fic_invoices', function (Blueprint $table) {
            $table->id();
            
            // Foreign key to fic_accounts
            $table->foreignId('fic_account_id')
                ->constrained('fic_accounts')
                ->onDelete('cascade')
                ->comment('Reference to FIC account');
            
            // FIC invoice identifier
            $table->unsignedBigInteger('fic_invoice_id')->comment('FIC invoice ID');
            
            // Invoice key fields
            $table->string('number')->nullable()->comment('Invoice number');
            $table->string('status')->nullable()->comment('Invoice status');
            $table->decimal('total_gross', 15, 2)->nullable()->comment('Total gross amount');
            $table->date('fic_date')->nullable()->comment('Invoice date from FIC');
            $table->timestamp('fic_created_at')->nullable()->comment('Creation date from FIC');
            
            // Raw JSON data from FIC API
            $table->json('raw')->nullable()->comment('Raw JSON data from FIC API');
            
            // Timestamps
            $table->timestamps();
            
            // Indexes
            $table->index('fic_account_id');
            $table->index('fic_invoice_id');
            $table->index(['fic_account_id', 'fic_invoice_id']);
            $table->index('fic_created_at');
            $table->index('fic_date');
            $table->unique(['fic_account_id', 'fic_invoice_id'], 'fic_invoices_account_invoice_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fic_invoices');
    }
};
