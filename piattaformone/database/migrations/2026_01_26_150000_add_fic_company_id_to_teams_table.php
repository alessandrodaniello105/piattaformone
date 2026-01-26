<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add fic_company_id column to teams table.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (!Schema::hasColumn('teams', 'fic_company_id')) {
                $table->string('fic_company_id')->nullable()->after('fic_redirect_uri');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (Schema::hasColumn('teams', 'fic_company_id')) {
                $table->dropColumn('fic_company_id');
            }
        });
    }
};
