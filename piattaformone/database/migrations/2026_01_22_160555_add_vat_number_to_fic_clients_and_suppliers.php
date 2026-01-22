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
        Schema::table('fic_clients', function (Blueprint $table) {
            $table->string('vat_number')->nullable()->after('code')->comment('Client VAT number');
            $table->index('vat_number');
        });

        Schema::table('fic_suppliers', function (Blueprint $table) {
            $table->string('vat_number')->nullable()->after('code')->comment('Supplier VAT number');
            $table->index('vat_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fic_clients', function (Blueprint $table) {
            $table->dropIndex(['vat_number']);
            $table->dropColumn('vat_number');
        });

        Schema::table('fic_suppliers', function (Blueprint $table) {
            $table->dropIndex(['vat_number']);
            $table->dropColumn('vat_number');
        });
    }
};
