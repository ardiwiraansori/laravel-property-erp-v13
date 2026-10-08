<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installations with business records require an explicit,
        // reviewed ownership backfill before adding the non-null tenant key.
        if (DB::table('property_developments')->exists()) {
            throw new \RuntimeException(
                'Property Developments already exist. Assign company ownership '
                . 'using a reviewed migration plan before applying tenant constraints.'
            );
        }

        Schema::table('property_developments', function (Blueprint $table): void {
            $table->foreignId('company_id')
                ->after('id')
                ->constrained('companies')
                ->restrictOnDelete();
        });

        Schema::table('property_developments', function (Blueprint $table): void {
            $table->dropUnique('property_developments_code_unique');
            $table->unique(['company_id', 'code'], 'property_developments_company_code_unique');
            $table->unique(['company_id', 'id'], 'property_developments_company_id_unique');
        });
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'Tenant ownership rollback requires an explicitly reviewed data-safe plan.'
        );
    }
};
