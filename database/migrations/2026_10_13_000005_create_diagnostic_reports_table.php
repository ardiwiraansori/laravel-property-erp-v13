<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_reports', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('reporter_user_id')->constrained('users')->restrictOnDelete();
            $table->string('category', 40);
            $table->string('module', 40);
            $table->string('impact', 20);
            $table->string('error_kind', 30);
            $table->string('browser_family', 20);
            $table->string('viewport', 20);
            $table->string('status', 20)->default('new');
            $table->timestamps();
            $table->index(['company_id', 'reporter_user_id', 'created_at'], 'diag_tenant_reporter_date_idx');
            $table->index(['company_id', 'status', 'created_at'], 'diag_tenant_status_date_idx');
            $table->index(['status', 'created_at'], 'diag_status_date_idx');
        });
    }

    public function down(): void
    {
        // Do not destroy user-generated incident records during incidental rollback.
        throw new RuntimeException('Diagnostic reports require an explicitly reviewed data-preserving rollback.');
    }
};
