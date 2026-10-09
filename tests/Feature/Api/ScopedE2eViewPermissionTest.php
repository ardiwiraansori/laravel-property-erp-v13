<?php

namespace Tests\Feature\Api;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Models\PropertyDevelopment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScopedE2eViewPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_scoped_direct_view_permissions_do_not_inherit_owner_or_other_company_data(): void
    {
        $companyA = Company::query()->create(['code' => 'E2E-A', 'name' => 'Test A']);
        $companyB = Company::query()->create(['code' => 'E2E-B', 'name' => 'Test B']);
        $user = User::factory()->create();
        $user->companies()->attach([$companyA->id, $companyB->id]);

        $allPermissions = [];
        foreach (PermissionName::cases() as $name) {
            $allPermissions[] = Permission::findOrCreate($name->value, 'web');
        }

        $owner = Role::findOrCreate('Owner', 'web');
        $owner->syncPermissions($allPermissions);

        $previousTeamId = getPermissionsTeamId();
        try {
            setPermissionsTeamId($companyA->id);
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->assignRole($owner);
        } finally {
            setPermissionsTeamId($previousTeamId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        $viewPermissions = [
            PermissionName::DashboardView->value,
            PermissionName::MasterDataView->value,
            PermissionName::MasterDataPropertyDevelopmentsView->value,
        ];

        $scopedRows = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $viewPermissions)
            ->get()
            ->map(fn (Permission $permission): array => [
                'permission_id' => $permission->id,
                'model_type' => $user->getMorphClass(),
                'model_id' => $user->id,
                'company_id' => $companyB->id,
            ])->all();

        DB::table('model_has_permissions')->insert($scopedRows);

        PropertyDevelopment::query()->create([
            'company_id' => $companyA->id,
            'code' => 'TEST-01',
            'name' => 'Test Residence',
        ]);

        $this->actingAs($user)
            ->withHeader('X-Company-ID', (string) $companyA->id)
            ->getJson('/api/company-context')
            ->assertOk()
            ->assertJsonPath('data.roles', ['Owner']);

        $this->getJson('/api/master-data/property-developments')
            ->assertOk()
            ->assertJsonFragment(['code' => 'TEST-01']);

        $companyBContext = $this->withHeader('X-Company-ID', (string) $companyB->id)
            ->getJson('/api/company-context')
            ->assertOk()
            ->assertJsonPath('data.roles', []);

        $this->assertSame(
            ['dashboard.view', 'master-data.property-developments.view', 'master-data.view'],
            $companyBContext->json('data.permissions'),
        );

        $this->getJson('/api/master-data/property-developments')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson('/api/master-data/property-developments', [
            'code' => 'MUST-NOT-EXIST',
            'name' => 'Forbidden Residence',
        ])->assertForbidden();

        $this->assertDatabaseMissing('property_developments', [
            'company_id' => $companyB->id,
            'code' => 'MUST-NOT-EXIST',
        ]);

        $this->withHeader('X-Company-ID', (string) $companyA->id)
            ->getJson('/api/company-context')
            ->assertOk()
            ->assertJsonPath('data.roles', ['Owner']);

        $this->getJson('/api/master-data/property-developments')
            ->assertOk()
            ->assertJsonFragment(['code' => 'TEST-01']);
    }
}
