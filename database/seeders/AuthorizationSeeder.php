<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = array_map(
            static fn (PermissionName $permission) => Permission::findOrCreate(
                $permission->value,
                'web',
            ),
            PermissionName::cases(),
        );

        $owner = Role::findOrCreate(RoleName::Owner->value, 'web');

        $owner->syncPermissions($permissions);
    }
}
