<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_company_role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->timestamp('archived_at');
            $table->unique(['role_id', 'model_id', 'model_type'], 'legacy_role_assignments_unique');
        });

        DB::transaction(function (): void {
            $assignments = DB::table('model_has_roles')
                ->whereNull('company_id')
                ->get(['role_id', 'model_type', 'model_id']);

            // No role assignment may be silently mapped to a company.
            foreach ($assignments as $assignment) {
                if ($assignment->model_type !== 'App\\Models\\User') {
                    throw new RuntimeException('Unsupported legacy role assignee; manual review required.');
                }

                if (DB::table('company_user')->where('user_id', $assignment->model_id)->exists()) {
                    throw new RuntimeException(
                        'A legacy role assignee has company memberships; decide ownership before migration.'
                    );
                }
            }

            if (DB::table('model_has_permissions')->whereNull('company_id')->exists()) {
                throw new RuntimeException('Legacy direct permission assignments require manual reconciliation.');
            }

            foreach ($assignments as $assignment) {
                DB::table('legacy_company_role_assignments')->insert([
                    'role_id' => $assignment->role_id,
                    'model_type' => $assignment->model_type,
                    'model_id' => $assignment->model_id,
                    'archived_at' => now(),
                ]);

                DB::table('model_has_roles')
                    ->where('role_id', $assignment->role_id)
                    ->where('model_type', $assignment->model_type)
                    ->where('model_id', $assignment->model_id)
                    ->whereNull('company_id')
                    ->delete();
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Legacy RBAC quarantine rollback requires reviewed restoration.');
    }
};
