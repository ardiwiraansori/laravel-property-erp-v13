<?php

namespace Tests\Feature\Api\MasterData;

use App\Enums\PermissionName;
use App\Models\PropertyDevelopment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PropertyDevelopmentTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermission(
        PermissionName $permissionName,
    ): User {
        $user = User::factory()->create();

        $permission = Permission::findOrCreate(
            $permissionName->value,
            'web',
        );

        $user->givePermissionTo($permission);

        return $user;
    }

    public function test_unauthenticated_user_cannot_access_property_developments(): void
    {
        $this->getJson('/api/master-data/property-developments')
            ->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_view_property_developments(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/master-data/property-developments')
            ->assertForbidden();
    }

    public function test_authorized_user_can_view_property_developments(): void
    {
        $user = $this->userWithPermission(
            PermissionName::MasterDataPropertyDevelopmentsView,
        );

        PropertyDevelopment::query()->create([
            'code' => 'ALPHA',
            'name' => 'Alpha Residence',
        ]);

        PropertyDevelopment::query()->create([
            'code' => 'BETA',
            'name' => 'Beta Residence',
        ]);

        $this->actingAs($user)
            ->getJson('/api/master-data/property-developments')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'ALPHA')
            ->assertJsonPath('data.0.name', 'Alpha Residence')
            ->assertJsonPath('data.0.is_active', true);
    }

    public function test_user_without_create_permission_cannot_create_property_development(): void
    {
        $user = $this->userWithPermission(
            PermissionName::MasterDataPropertyDevelopmentsView,
        );

        $this->actingAs($user)
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
        $user = $this->userWithPermission(
            PermissionName::MasterDataPropertyDevelopmentsCreate,
        );

        $this->actingAs($user)
            ->postJson('/api/master-data/property-developments', [
                'code' => ' griya-01 ',
                'name' => ' Griya Residence ',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'GRIYA-01')
            ->assertJsonPath('data.name', 'Griya Residence')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('property_developments', [
            'code' => 'GRIYA-01',
            'name' => 'Griya Residence',
            'is_active' => true,
        ]);
    }

    public function test_property_development_code_must_be_unique(): void
    {
        $user = $this->userWithPermission(
            PermissionName::MasterDataPropertyDevelopmentsCreate,
        );

        PropertyDevelopment::query()->create([
            'code' => 'GRIYA-01',
            'name' => 'First Development',
        ]);

        $this->actingAs($user)
            ->postJson('/api/master-data/property-developments', [
                'code' => 'griya-01',
                'name' => 'Second Development',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }
}
