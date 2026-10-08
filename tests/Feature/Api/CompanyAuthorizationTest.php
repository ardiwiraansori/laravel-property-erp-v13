<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code): Company
    {
        return Company::query()->create(['code' => $code, 'name' => 'Company '.$code]);
    }

    private function grantRole(User $user, Company $company, string $name, array $permissions): void
    {
        $previous = getPermissionsTeamId();

        try {
            setPermissionsTeamId($company->id);
            $role = Role::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web', 'company_id' => $company->id],
            );

            foreach ($permissions as $permission) {
                $role->givePermissionTo(Permission::findOrCreate($permission->value, 'web'));
            }

            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->assignRole($role);
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function test_company_context_returns_only_current_company_roles_and_permissions(): void
    {
        $first = $this->company('FIRST');
        $second = $this->company('SECOND');
        $user = User::factory()->create();
        $user->companies()->attach([$first->id, $second->id]);

        $this->grantRole($user, $first, 'Manager', [PermissionName::MasterDataPropertyDevelopmentsView]);
        $this->grantRole($user, $second, 'Editor', [PermissionName::MasterDataPropertyDevelopmentsCreate]);

        $this->actingAs($user)
            ->withHeader('X-Company-ID', (string) $first->id)
            ->getJson('/api/company-context')
            ->assertOk()
            ->assertJsonPath('data.id', $first->id)
            ->assertJsonPath('data.roles', ['Manager'])
            ->assertJsonPath('data.permissions', [PermissionName::MasterDataPropertyDevelopmentsView->value]);

        $this->withHeader('X-Company-ID', (string) $second->id)
            ->getJson('/api/company-context')
            ->assertOk()
            ->assertJsonPath('data.id', $second->id)
            ->assertJsonPath('data.roles', ['Editor'])
            ->assertJsonPath('data.permissions', [PermissionName::MasterDataPropertyDevelopmentsCreate->value]);

        $this->withHeader('X-Company-ID', (string) $first->id)
            ->getJson('/api/company-context')
            ->assertOk()
            ->assertJsonPath('data.roles', ['Manager']);
    }

    public function test_company_role_does_not_authorize_other_company_mutations(): void
    {
        $first = $this->company('FIRST');
        $second = $this->company('SECOND');
        $user = User::factory()->create();
        $user->companies()->attach([$first->id, $second->id]);
        $this->grantRole($user, $first, 'Writer', [PermissionName::MasterDataPropertyDevelopmentsCreate]);

        $this->actingAs($user)
            ->withHeader('X-Company-ID', (string) $first->id)
            ->postJson('/api/master-data/property-developments', [
                'code' => 'SAFE', 'name' => 'Allowed Residence',
            ])->assertCreated();

        $this->withHeader('X-Company-ID', (string) $second->id)
            ->postJson('/api/master-data/property-developments', [
                'code' => 'DENIED', 'name' => 'Denied Residence',
            ])->assertForbidden();

        $this->assertDatabaseMissing('property_developments', [
            'company_id' => $second->id, 'code' => 'DENIED',
        ]);
    }

    public function test_company_context_rejects_non_member_even_with_a_role_elsewhere(): void
    {
        $first = $this->company('FIRST');
        $second = $this->company('SECOND');
        $user = User::factory()->create();
        $user->companies()->attach($first->id);
        $this->grantRole($user, $first, 'Manager', [PermissionName::MasterDataPropertyDevelopmentsView]);

        $this->actingAs($user)
            ->withHeader('X-Company-ID', (string) $second->id)
            ->getJson('/api/company-context')
            ->assertForbidden();
    }
}
