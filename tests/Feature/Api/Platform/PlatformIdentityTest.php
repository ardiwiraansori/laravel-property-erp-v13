<?php

namespace Tests\Feature\Api\Platform;

use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function createCompany(string $code = 'TENANT-A', bool $active = true): Company
    {
        return Company::query()->create([
            'code' => $code,
            'name' => 'Tenant '.$code,
            'is_active' => $active,
        ]);
    }

    private function grantPlatformAccess(User $user, bool $active = true): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'user_id' => $user->id,
            'is_active' => $active,
            'granted_at' => now(),
        ]);
    }

    public function test_guest_cannot_access_platform_identity(): void
    {
        $this->getJson('/api/platform/me')->assertUnauthorized();
    }

    public function test_ordinary_authenticated_user_is_not_auto_provisioned(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseCount('platform_admins', 0);

        $this->actingAs($user)
            ->getJson('/api/platform/me')
            ->assertForbidden();
    }

    public function test_tenant_member_is_not_authorized_for_platform_route(): void
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->createCompany()->id);

        $this->actingAs($user)
            ->getJson('/api/platform/me')
            ->assertForbidden();
    }

    public function test_active_platform_admin_without_membership_can_read_identity(): void
    {
        $user = User::factory()->create();
        $this->grantPlatformAccess($user);

        $this->actingAs($user)
            ->getJson('/api/platform/me')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'platform_access' => true,
                ],
            ]);
    }

    public function test_inactive_platform_admin_is_denied(): void
    {
        $user = User::factory()->create();
        $this->grantPlatformAccess($user, false);

        $this->actingAs($user)
            ->getJson('/api/platform/me')
            ->assertForbidden();
    }

    public function test_revoked_platform_admin_is_denied_even_if_active(): void
    {
        $user = User::factory()->create();
        $platformAdmin = $this->grantPlatformAccess($user);
        $platformAdmin->update(['revoked_at' => now()]);

        $this->actingAs($user)
            ->getJson('/api/platform/me')
            ->assertForbidden();
    }

    public function test_adding_tenant_membership_immediately_removes_platform_access(): void
    {
        $user = User::factory()->create();
        $this->grantPlatformAccess($user);
        $this->actingAs($user)->getJson('/api/platform/me')->assertOk();

        $user->companies()->attach($this->createCompany()->id);

        $this->getJson('/api/platform/me')->assertForbidden();
    }

    public function test_inactive_tenant_membership_still_blocks_platform_access(): void
    {
        $user = User::factory()->create();
        $this->grantPlatformAccess($user);
        $user->companies()->attach($this->createCompany('INACTIVE', false)->id);

        $this->actingAs($user)
            ->getJson('/api/platform/me')
            ->assertForbidden();
    }

    public function test_tenant_header_is_rejected_on_platform_route(): void
    {
        $user = User::factory()->create();
        $this->grantPlatformAccess($user);

        $this->actingAs($user)
            ->withHeader('X-Company-ID', '1')
            ->getJson('/api/platform/me')
            ->assertStatus(422)
            ->assertJsonPath('message', 'X-Company-ID is not accepted on platform endpoints.');
    }

    public function test_platform_admin_does_not_gain_tenant_access_without_membership(): void
    {
        $company = $this->createCompany();
        $user = User::factory()->create();
        $this->grantPlatformAccess($user);

        $this->actingAs($user)
            ->withHeader('X-Company-ID', (string) $company->id)
            ->getJson('/api/company-context')
            ->assertForbidden();
    }

    public function test_new_platform_admin_record_is_inactive_by_default(): void
    {
        $user = User::factory()->create();
        PlatformAdmin::query()->create([
            'user_id' => $user->id,
            'granted_at' => now(),
        ]);

        $this->assertDatabaseHas('platform_admins', [
            'user_id' => $user->id,
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->getJson('/api/platform/me')
            ->assertForbidden();
    }
}
