<?php

namespace Tests\Feature\Api\MasterData;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Models\PropertyDevelopment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PropertyDevelopmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $code = 'ARVYA'): Company
    {
        return Company::query()->create([
            'code' => $code,
            'name' => 'Company ' . $code,
        ]);
    }

    private function memberOf(Company $company): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company->id);

        return $user;
    }

    private function userWithPermission(Company $company, PermissionName $name): User
    {
        $user = $this->memberOf($company);
        $permission = Permission::findOrCreate($name->value, 'web');
        $previousTeam = getPermissionsTeamId();
        try {
            setPermissionsTeamId($company->id);
            $user->givePermissionTo($permission);
        } finally {
            setPermissionsTeamId($previousTeam);
        }

        return $user;
    }

    private function makeDevelopment(Company $company, string $code, string $name): PropertyDevelopment
    {
        return PropertyDevelopment::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'name' => $name,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_property_developments(): void
    {
        $company = $this->makeCompany();

        $this->withHeader('X-Company-ID', (string) $company->id)
            ->getJson('/api/master-data/property-developments')
            ->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_view_property_developments(): void
    {
        $company = $this->makeCompany();
        $user = $this->memberOf($company);

        $this->actingAs($user)
            ->withHeader('X-Company-ID', (string) $company->id)
            ->getJson('/api/master-data/property-developments')
            ->assertForbidden();
    }

    public function test_authorized_user_can_view_property_developments(): void
    {
        $company = $this->makeCompany();
        $otherCompany = $this->makeCompany('OTHER');
        $user = $this->userWithPermission($company, PermissionName::MasterDataPropertyDevelopmentsView);

        $this->makeDevelopment($company, 'ALPHA', 'Alpha Residence');
        $this->makeDevelopment($company, 'BETA', 'Beta Residence');
        $this->makeDevelopment($otherCompany, 'SECRET', 'Other Company Residence');

        $this->actingAs($user)
            ->withHeader('X-Company-ID', (string) $company->id)
            ->getJson('/api/master-data/property-developments')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'ALPHA')
            ->assertJsonPath('data.0.name', 'Alpha Residence')
            ->assertJsonPath('data.0.is_active', true)
            ->assertDontSee('SECRET');
    }

    public function test_user_without_create_permission_cannot_create_property_development(): void
    {
        $company = $this->makeCompany();
        $user = $this->userWithPermission($company, PermissionName::MasterDataPropertyDevelopmentsView);

        $this->actingAs($user)
            ->withHeader('X-Company-ID', (string) $company->id)
            ->postJson('/api/master-data/property-developments', [
                'code' => 'DENIED-01',
                'name' => 'Denied Residence',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('property_developments', [
            'code' => 'DENIED-01',
        ]);
    }

    public function test_authorized_user_can_create_property_development(): void
    {
        $company = $this->makeCompany();
        $user = $this->userWithPermission($company, PermissionName::MasterDataPropertyDevelopmentsCreate);

        $this->actingAs($user)
            ->withHeader('X-Company-ID', (string) $company->id)
            ->postJson('/api/master-data/property-developments', [
                'code' => ' griya-01 ',
                'name' => ' Griya Residence ',
                'company_id' => 9999,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'GRIYA-01')
            ->assertJsonPath('data.name', 'Griya Residence')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('property_developments', [
            'company_id' => $company->id,
            'code' => 'GRIYA-01',
            'name' => 'Griya Residence',
            'is_active' => true,
        ]);
    }

    public function test_property_development_code_must_be_unique_within_company(): void
    {
        $company = $this->makeCompany();
        $user = $this->userWithPermission($company, PermissionName::MasterDataPropertyDevelopmentsCreate);
        $this->makeDevelopment($company, 'GRIYA-01', 'First Development');

        $this->actingAs($user)
            ->withHeader('X-Company-ID', (string) $company->id)
            ->postJson('/api/master-data/property-developments', [
                'code' => 'griya-01',
                'name' => 'Second Development',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_different_companies_can_use_the_same_development_code(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany('OTHER');
        $user = $this->userWithPermission($company, PermissionName::MasterDataPropertyDevelopmentsCreate);
        $this->makeDevelopment($other, 'GRIYA-01', 'Other Development');

        $this->actingAs($user)
            ->withHeader('X-Company-ID', (string) $company->id)
            ->postJson('/api/master-data/property-developments', [
                'code' => 'GRIYA-01',
                'name' => 'Our Development',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('property_developments', 2);
    }

    public function test_non_member_cannot_access_a_company_even_with_permission(): void
    {
        $company = $this->makeCompany();
        $other = $this->makeCompany('OTHER');
        $user = $this->userWithPermission($company, PermissionName::MasterDataPropertyDevelopmentsView);
        $this->makeDevelopment($other, 'PRIVATE', 'Private Development');

        $this->actingAs($user)
            ->withHeader('X-Company-ID', (string) $other->id)
            ->getJson('/api/master-data/property-developments')
            ->assertForbidden();
    }

    public function test_company_header_is_required_for_tenant_routes(): void
    {
        $company = $this->makeCompany();
        $user = $this->userWithPermission($company, PermissionName::MasterDataPropertyDevelopmentsView);

        $this->actingAs($user)
            ->getJson('/api/master-data/property-developments')
            ->assertUnprocessable();
    }

    public function test_inactive_company_cannot_be_selected(): void
    {
        $company = $this->makeCompany();
        $company->update(['is_active' => false]);
        $user = $this->userWithPermission($company, PermissionName::MasterDataPropertyDevelopmentsView);

        $this->actingAs($user)
            ->withHeader('X-Company-ID', (string) $company->id)
            ->getJson('/api/master-data/property-developments')
            ->assertForbidden();
    }
}
