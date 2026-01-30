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
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fic_client_id')
                ->constrained('fic_clients')
                ->onDelete('cascade');
            $table->string('category')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('gross', 10, 2)->nullable();
            $table->timestamps();

            // Index for faster queries by client and date range
            $table->index(['fic_client_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};
