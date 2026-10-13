<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_report_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('diagnostic_report_id')->constrained('diagnostic_reports')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('from_assignee_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('to_assignee_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['diagnostic_report_id', 'id'], 'diag_event_report_idx');
            $table->index(['company_id', 'created_at'], 'diag_event_tenant_date_idx');
        });
    }

    public function down(): void
    {
        // Append-only support history; never silently drop audit events.
        throw new RuntimeException('Diagnostic event rollback requires an explicitly reviewed data-preserving procedure.');
    }
};
