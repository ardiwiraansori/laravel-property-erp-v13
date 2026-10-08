<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class ProvisionArvyaOwner extends Command
{
    protected $signature = 'company:provision-arvya-owner';

    protected $description = 'Grant Owner exclusively to the verified initial Arvya administrator';

    public function handle(): int
    {
        $company = Company::query()->where('code', 'ARVYA')->first();
        $user = User::query()->find(1);

        if (! $company || ! $company->is_active || ! $user ||
            ! $user->companies()->whereKey($company->id)->exists()) {
            $this->error('Provisioning stopped: ARVYA or administrator membership was not verified.');
            return self::FAILURE;
        }

        if (! config('permission.teams') || config('permission.column_names.team_foreign_key') !== 'company_id') {
            $this->error('Provisioning stopped: Spatie Teams is not correctly configured.');
            return self::FAILURE;
        }

        $previous = getPermissionsTeamId();
        try {
            setPermissionsTeamId($company->id);
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $role = Role::findByName(RoleName::Owner->value, 'web');
            $user->assignRole($role);
        } finally {
            setPermissionsTeamId($previous);
        }

        $this->info('Owner assigned to administrator user ID 1 in company ARVYA only.');
        return self::SUCCESS;
    }
}
