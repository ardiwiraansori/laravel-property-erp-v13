<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand only. Legacy role/permission assignments must remain untouched until
     * ownership is explicitly reconciled and Spatie Teams can be enabled safely.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable()->index('roles_company_id_index');
        });

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable()->index('model_has_roles_company_id_index');
        });

        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable()->index('model_has_permissions_company_id_index');
        });
    }

    public function down(): void
    {
        // Rollback is deliberately blocked to avoid losing tenant assignment data
        // once subsequent RBAC migrations have populated these fields.
        throw new RuntimeException(
            'Company-scoped RBAC schema rollback requires a reviewed data-preserving migration.'
        );
    }
};
