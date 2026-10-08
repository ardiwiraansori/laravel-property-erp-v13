<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckCompanyRbacReadiness extends Command
{
    protected $signature = 'company:rbac-readiness';

    protected $description = 'Read-only checks before activating company-scoped Spatie Teams';

    public function handle(): int
    {
        $this->info('Company RBAC readiness (READ ONLY)');
        $this->line('Spatie teams enabled: '.(config('permission.teams') ? 'yes' : 'no'));
        $this->line('Spatie team key: '.(string) config('permission.column_names.team_foreign_key'));

        foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            $has = Schema::hasColumn($table, 'company_id');
            $this->line($table.'.company_id: '.($has ? 'present' : 'MISSING'));
            if (! $has) {
                $this->error('RBAC schema preparation is incomplete.');
                return self::FAILURE;
            }
        }

        $legacyRoles = DB::table('model_has_roles')->whereNull('company_id')->count();
        $legacyPermissions = DB::table('model_has_permissions')->whereNull('company_id')->count();
        $this->line('Unscoped role assignments: '.$legacyRoles);
        $this->line('Unscoped direct permission assignments: '.$legacyPermissions);

        $assignments = DB::table('model_has_roles as mr')
            ->leftJoin('company_user as cu', function ($join): void {
                $join->on('cu.user_id', '=', 'mr.model_id');
            })
            ->whereNull('mr.company_id')
            ->where('mr.model_type', '=', 'App\Models\User')
            ->select('mr.model_id', 'mr.role_id', DB::raw('COUNT(cu.company_id) as membership_count'))
            ->groupBy('mr.model_id', 'mr.role_id')
            ->orderBy('mr.model_id')
            ->get();

        if ($assignments->isNotEmpty()) {
            $this->table(['user_id', 'role_id', 'memberships'], $assignments->map(
                fn ($row): array => [(string) $row->model_id, (string) $row->role_id, (string) $row->membership_count]
            )->all());
        }

        $this->warn('No assignments were migrated. Do NOT enable Spatie Teams yet.');
        return self::SUCCESS;
    }
}
