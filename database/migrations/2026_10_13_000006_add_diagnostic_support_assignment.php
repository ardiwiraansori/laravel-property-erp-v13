<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive only; existing reports retain their original status and fields.
        Schema::table('diagnostic_reports', function (Blueprint $table): void {
            $table->foreignId('assigned_platform_admin_user_id')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('workflow_version')->default(0);
            $table->index(['assigned_platform_admin_user_id', 'status'], 'diag_assignee_status_idx');
        });
    }

    public function down(): void
    {
        // Preserve assignment and incident history during incidental rollback.
        throw new RuntimeException('Diagnostic support workflow rollback requires an explicitly reviewed data-preserving procedure.');
    }
};
