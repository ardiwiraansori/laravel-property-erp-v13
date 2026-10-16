<?php

namespace Tests\Feature\Api\MasterData;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Models\PropertyDevelopment;
use App\Models\User;
use App\Support\MasterData\PropertyDevelopmentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Checkpoint 7.18: explicit update permission and tenant-scoped snapshot CAS. */
final class PropertyDevelopmentUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $code = 'TENANT_A'): Company
    {
        return Company::query()->create(['code' => $code, 'name' => 'Test '.$code]);
    }

    private function member(Company $company, array $permissions = []): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company->id);
        foreach ($permissions as $permission) {
            $this->grant($user, $company, $permission);
        }
        return $user;
    }

    private function grant(User $user, Company $company, string|PermissionName $permission): void
    {
        $record = Permission::findOrCreate($permission instanceof PermissionName ? $permission->value : $permission, 'web');
        $previous = getPermissionsTeamId();
        try {
            setPermissionsTeamId($company->id);
            $user->givePermissionTo($record);
        } finally {
            setPermissionsTeamId($previous);
        }
    }

    private function development(Company $company, string $code = 'GARDEN'): PropertyDevelopment
    {
        return PropertyDevelopment::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'name' => 'Garden Residence',
            'is_active' => true,
        ]);
    }

    private function url(PropertyDevelopment $development): string
    {
        return '/api/master-data/property-developments/'.$development->id;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Garden Residence Updated',
            'is_active' => false,
            'expected_name' => 'Garden Residence',
            'expected_is_active' => true,
        ], $overrides);
    }

    private function login(User $user, Company $company): void
    {
        $this->actingAs($user)->withHeader('X-Company-ID', (string) $company->id);
    }

    public function test_granted_updater_can_rename_and_deactivate_only_own_development(): void
    {
        $company = $this->company();
        $development = $this->development($company);
        $this->login($this->member($company, [PropertyDevelopmentAccess::UPDATE]), $company);

        $this->patchJson($this->url($development), $this->payload(['name' => ' New Garden ']))
            ->assertOk()->assertJsonPath('data.code', 'GARDEN')
            ->assertJsonPath('data.name', 'New Garden')->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('property_developments', [
            'id' => $development->id, 'company_id' => $company->id,
            'code' => 'GARDEN', 'name' => 'New Garden', 'is_active' => false,
        ]);
    }

    public function test_new_update_permission_is_not_implied_by_read_or_create(): void
    {
        $company = $this->company();
        $development = $this->development($company);
        $user = $this->member($company, [
            PermissionName::MasterDataPropertyDevelopmentsView,
            PermissionName::MasterDataPropertyDevelopmentsCreate,
        ]);
        $this->login($user, $company);
        $this->patchJson($this->url($development), $this->payload())->assertForbidden();
        $this->assertDatabaseHas('property_developments', ['id' => $development->id, 'is_active' => true]);
    }

    public function test_member_without_any_permission_cannot_update(): void
    {
        $company = $this->company();
        $development = $this->development($company);
        $this->login($this->member($company), $company);
        $this->patchJson($this->url($development), $this->payload())->assertForbidden();
    }

    public function test_guest_missing_header_and_nonmember_cannot_update(): void
    {
        $company = $this->company();
        $development = $this->development($company);
        $this->patchJson($this->url($development), $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->patchJson($this->url($development), $this->payload())->assertUnprocessable();
        $this->withHeader('X-Company-ID', (string) $company->id)
            ->patchJson($this->url($development), $this->payload())->assertForbidden();
    }

    public function test_known_record_id_in_another_company_returns_not_found(): void
    {
        $first = $this->company();
        $second = $this->company('TENANT_B');
        $secret = $this->development($second, 'SECRET');
        $this->login($this->member($first, [PropertyDevelopmentAccess::UPDATE]), $first);
        $this->patchJson($this->url($secret), $this->payload())->assertNotFound();
        $this->assertDatabaseHas('property_developments', ['id' => $secret->id, 'company_id' => $second->id, 'name' => 'Garden Residence']);
    }

    public function test_same_user_two_companies_must_hold_permission_for_selected_company(): void
    {
        $first = $this->company();
        $second = $this->company('TENANT_B');
        $development = $this->development($second);
        $user = $this->member($first, [PropertyDevelopmentAccess::UPDATE]);
        $user->companies()->attach($second->id);

        $this->login($user, $second);
        $this->patchJson($this->url($development), $this->payload())->assertForbidden();
        $this->grant($user, $second, PropertyDevelopmentAccess::UPDATE);
        $this->patchJson($this->url($development), $this->payload())->assertOk();
    }

    public function test_code_company_and_other_non_allowlisted_fields_cannot_be_modified(): void
    {
        $first = $this->company();
        $second = $this->company('TENANT_B');
        $development = $this->development($first);
        $this->login($this->member($first, [PropertyDevelopmentAccess::UPDATE]), $first);
        $this->patchJson($this->url($development), $this->payload([
            'code' => 'CHANGED', 'company_id' => $second->id,
            'id' => 99999, 'created_at' => '2000-01-01 00:00:00',
        ]))->assertOk();
        $this->assertDatabaseHas('property_developments', [
            'id' => $development->id, 'code' => 'GARDEN', 'company_id' => $first->id,
        ]);
        $this->assertDatabaseMissing('property_developments', ['code' => 'CHANGED']);
    }

    public function test_stale_snapshot_returns_409_and_cannot_overwrite_newer_edit(): void
    {
        $company = $this->company();
        $development = $this->development($company);
        $this->login($this->member($company, [PropertyDevelopmentAccess::UPDATE]), $company);
        $this->patchJson($this->url($development), $this->payload())->assertOk();
        $this->patchJson($this->url($development), $this->payload(['name' => 'Stale overwrite']))->assertStatus(409);
        $this->assertDatabaseHas('property_developments', [
            'id' => $development->id, 'name' => 'Garden Residence Updated', 'is_active' => false,
        ]);
    }

    public function test_inactive_development_can_be_reactivated_using_fresh_snapshot(): void
    {
        $company = $this->company();
        $development = $this->development($company);
        $this->login($this->member($company, [PropertyDevelopmentAccess::UPDATE]), $company);
        $this->patchJson($this->url($development), $this->payload())->assertOk();
        $this->patchJson($this->url($development), $this->payload([
            'name' => 'Garden Residence Updated', 'is_active' => true,
            'expected_name' => 'Garden Residence Updated', 'expected_is_active' => false,
        ]))->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_invalid_fields_and_missing_snapshot_return_422(): void
    {
        $company = $this->company();
        $development = $this->development($company);
        $this->login($this->member($company, [PropertyDevelopmentAccess::UPDATE]), $company);
        foreach ([
            $this->payload(['name' => '   ']),
            $this->payload(['name' => str_repeat('X', 151)]),
            $this->payload(['is_active' => 'perhaps']),
            $this->payload(['expected_is_active' => 'sometimes']),
            ['name' => 'No snapshot', 'is_active' => false],
        ] as $invalid) {
            $this->patchJson($this->url($development), $invalid)->assertUnprocessable();
        }
        $this->assertDatabaseHas('property_developments', ['id' => $development->id, 'name' => 'Garden Residence', 'is_active' => true]);
    }

    public function test_non_numeric_or_absent_record_returns_404(): void
    {
        $company = $this->company();
        $this->login($this->member($company, [PropertyDevelopmentAccess::UPDATE]), $company);
        $this->patchJson('/api/master-data/property-developments/abc', $this->payload())->assertNotFound();
        $this->patchJson('/api/master-data/property-developments/987654321', $this->payload())->assertNotFound();
    }

    public function test_inactive_company_cannot_update_and_noop_keeps_timestamp(): void
    {
        $company = $this->company();
        $development = $this->development($company);
        $this->login($this->member($company, [PropertyDevelopmentAccess::UPDATE]), $company);
        $before = $development->updated_at?->toISOString();
        $this->patchJson($this->url($development), $this->payload([
            'name' => 'Garden Residence', 'is_active' => true,
        ]))->assertOk();
        $this->assertSame($before, $development->fresh()->updated_at?->toISOString());
        $company->update(['is_active' => false]);
        $this->patchJson($this->url($development), $this->payload())->assertForbidden();
        $this->assertDatabaseHas('property_developments', ['id' => $development->id, 'name' => 'Garden Residence']);
    }
}
