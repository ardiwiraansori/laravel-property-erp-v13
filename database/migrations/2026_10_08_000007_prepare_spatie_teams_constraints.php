<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $name) {
            if (! Schema::hasColumn($name, 'company_id')) {
                throw new RuntimeException("Missing {$name}.company_id; migration 000005 must run first.");
            }
        }

        foreach (['model_has_roles', 'model_has_permissions'] as $name) {
            if (DB::table($name)->whereNull('company_id')->exists()) {
                throw new RuntimeException("Unscoped assignments found in {$name}; stop and review.");
            }
        }

        // MariaDB uses the old PRIMARY KEY (role_id, model_id, model_type)
        // as the supporting index for the role_id foreign key. Create a
        // replacement index BEFORE dropping the primary key.
        if (! Schema::hasIndex('model_has_roles', 'model_has_roles_role_id_fk_index')) {
            Schema::table('model_has_roles', function (Blueprint $table): void {
                $table->index('role_id', 'model_has_roles_role_id_fk_index');
            });
        }

        // Likewise for the permission_id foreign key.
        if (! Schema::hasIndex('model_has_permissions', 'model_has_permissions_permission_id_fk_index')) {
            Schema::table('model_has_permissions', function (Blueprint $table): void {
                $table->index('permission_id', 'model_has_permissions_permission_id_fk_index');
            });
        }

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->dropPrimary('model_has_roles_role_model_type_primary');
            $table->primary(
                ['company_id', 'role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary',
            );
        });

        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->dropPrimary('model_has_permissions_permission_model_type_primary');
            $table->primary(
                ['company_id', 'permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary',
            );
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Manual review is required for a data-preserving RBAC rollback.');
    }
};
