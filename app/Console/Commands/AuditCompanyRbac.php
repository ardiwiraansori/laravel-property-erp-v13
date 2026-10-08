<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditCompanyRbac extends Command
{
    protected $signature = 'company:rbac-audit';

    protected $description = 'Read-only audit of legacy role assignments and company memberships';

    public function handle(): int
    {
        $this->info('Company RBAC migration audit (READ ONLY)');

        foreach (['companies', 'company_user', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions'] as $table) {
            $this->line($table . ': ' . DB::table($table)->count());
        }

        $this->newLine();
        $this->info('Memberships (company_id, user_id)');
        $memberships = DB::table('company_user')->orderBy('user_id')->get(['company_id', 'user_id']);
        $this->table(['company_id', 'user_id'], $memberships->map(fn ($r) => [(string) $r->company_id, (string) $r->user_id])->all());

        $this->newLine();
        $this->info('Role definitions');
        $roles = DB::table('roles')->orderBy('id')->get(['id', 'name', 'guard_name']);
        $this->table(['role_id', 'name', 'guard'], $roles->map(fn ($r) => [(string) $r->id, $r->name, $r->guard_name])->all());

        $this->newLine();
        $this->info('Legacy role assignments (user_id, role_id, memberships)');
        $assignments = DB::table('model_has_roles')->where('model_type', 'App\\Models\\User')
            ->orderBy('model_id')->get(['model_id', 'role_id']);
        $rows = [];
        foreach ($assignments as $assignment) {
            $owned = $memberships->where('user_id', $assignment->model_id)->pluck('company_id')->implode(',');
            $rows[] = [(string) $assignment->model_id, (string) $assignment->role_id, $owned === '' ? '(none)' : $owned];
        }
        $this->table(['user_id', 'role_id', 'member_companies'], $rows);

        $this->newLine();
        $this->info('Legacy direct permission assignments (user_id, permission_id, memberships)');
        $direct = DB::table('model_has_permissions')->where('model_type', 'App\\Models\\User')
            ->orderBy('model_id')->get(['model_id', 'permission_id']);
        $rows = [];
        foreach ($direct as $assignment) {
            $owned = $memberships->where('user_id', $assignment->model_id)->pluck('company_id')->implode(',');
            $rows[] = [(string) $assignment->model_id, (string) $assignment->permission_id, $owned === '' ? '(none)' : $owned];
        }
        $this->table(['user_id', 'permission_id', 'member_companies'], $rows);

        $this->warn('Audit only: no records were created, updated, or deleted.');

        return self::SUCCESS;
    }
}
