<?php

namespace Tests\Feature\Api\Platform;

use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\PropertyDevelopment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Concerns\WithVerifiedPlatformMfa;

final class PlatformDirectoryTest extends TestCase
{
    use RefreshDatabase, WithVerifiedPlatformMfa;

    private function admin(): User
    {
        $user = User::factory()->create();
        PlatformAdmin::query()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);

        $this->withVerifiedPlatformMfa($user);
        return $user;
    }

    private function company(string $code, bool $active = true): Company
    {
        return Company::query()->create([
            'code' => $code,
            'name' => 'Company '.$code,
            'is_active' => $active,
        ]);
    }

    public function test_guest_cannot_access_any_platform_directory(): void
    {
        $this->getJson('/api/platform/companies')->assertUnauthorized();
        $this->getJson('/api/platform/users')->assertUnauthorized();
        $this->getJson('/api/platform/users/1/memberships')->assertUnauthorized();
    }

    public function test_tenant_member_cannot_read_any_platform_directory(): void
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company('ARVYA')->id);

        $this->actingAs($user)->getJson('/api/platform/companies')->assertForbidden();
        $this->getJson('/api/platform/users')->assertForbidden();
        $this->getJson('/api/platform/users/'.$user->id.'/memberships')->assertForbidden();
    }

    public function test_active_platform_admin_can_list_companies_without_tenant_records(): void
    {
        $first = $this->company('FIRST');
        $second = $this->company('SECOND', false);
        $tenantUser = User::factory()->create();
        $tenantUser->companies()->attach([$first->id, $second->id]);
        PropertyDevelopment::query()->create([
            'company_id' => $first->id,
            'code' => 'PRIVATE',
            'name' => 'Private development',
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/platform/companies')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.members_count', 1)
            ->assertJsonPath('data.1.members_count', 1);

        $this->assertSame(
            ['id', 'code', 'name', 'is_active', 'members_count', 'created_at'],
            array_keys($response->json('data.0')),
        );
        $response->assertDontSee('Private development');
        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_user_id' => $this->app['auth']->id(), 'action' => 'platform.companies.read',
        ]);
    }

    public function test_company_list_is_paginated_and_searchable(): void
    {
        $this->company('AAA');
        $this->company('BBB');
        $this->company('CCC');
        $this->actingAs($this->admin());

        $this->getJson('/api/platform/companies?per_page=1&page=2')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/platform/companies?search=BBB')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BBB');
    }

    public function test_user_directory_returns_only_approved_fields(): void
    {
        $member = User::factory()->create(['name' => 'Tenant Member', 'email' => 'tenant@example.com']);
        $member->companies()->attach($this->company('FIRST')->id);
        $platformAdmin = $this->admin();

        $response = $this->actingAs($platformAdmin)->getJson('/api/platform/users')
            ->assertOk()->assertJsonPath('meta.total', 2);

        $entries = $response->json('data');
        $this->assertSame(['id', 'name', 'email', 'memberships_count'], array_keys($entries[0]));
        $matched = collect($entries)->firstWhere('id', $member->id);
        $this->assertSame(1, $matched['memberships_count']);
        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_user_id' => $platformAdmin->id, 'action' => 'platform.users.read',
        ]);
        $response->assertDontSee('password')->assertDontSee('remember_token')
            ->assertDontSee('email_verified_at')->assertDontSee('permission');
    }

    public function test_users_can_be_searched_and_paginated(): void
    {
        User::factory()->create(['name' => 'Alpha Support', 'email' => 'alpha@example.com']);
        User::factory()->create(['name' => 'Beta Tenant', 'email' => 'beta@example.com']);
        $this->actingAs($this->admin());

        $this->getJson('/api/platform/users?search=Beta')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'beta@example.com');

        $this->getJson('/api/platform/users?per_page=1&page=2')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_memberships_include_inactive_company_but_not_roles_or_business_data(): void
    {
        $first = $this->company('FIRST');
        $inactive = $this->company('OLD', false);
        $user = User::factory()->create();
        $user->companies()->attach([$first->id, $inactive->id]);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/platform/users/'.$user->id.'/memberships')
            ->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data');

        $this->assertSame(['id', 'code', 'name', 'is_active'], array_keys($response->json('data.0')));
        $this->assertContains(false, array_column($response->json('data'), 'is_active'));
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'platform.user_memberships.read',
            'target_type' => 'user', 'target_id' => $user->id,
        ]);
    }

    public function test_missing_user_returns_not_found(): void
    {
        $this->actingAs($this->admin())->getJson('/api/platform/users/999999/memberships')
            ->assertNotFound();
    }

    public function test_revoking_admin_immediately_blocks_directory_queries(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson('/api/platform/users')->assertOk();
        $admin->platformAdmin()->update(['revoked_at' => now()]);
        $this->getJson('/api/platform/users')->assertForbidden();
        $this->getJson('/api/platform/companies')->assertForbidden();
    }

    public function test_platform_directory_rejects_tenant_context_header(): void
    {
        $this->actingAs($this->admin())->withHeader('X-Company-ID', '1')
            ->getJson('/api/platform/companies')->assertStatus(422);
        $this->getJson('/api/platform/users')->assertStatus(422);
    }

    public function test_invalid_pagination_parameters_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $this->getJson('/api/platform/companies?per_page=1000')->assertUnprocessable();
        $this->getJson('/api/platform/users?page=0')->assertUnprocessable();
        $this->getJson('/api/platform/users?search='.str_repeat('x', 101))
            ->assertUnprocessable();
    }

    public function test_platform_listing_does_not_grant_access_to_tenant_api(): void
    {
        $company = $this->company('FIRST');
        $this->actingAs($this->admin())->getJson('/api/platform/companies')->assertOk();
        $this->withHeader('X-Company-ID', (string) $company->id)
            ->getJson('/api/company-context')->assertForbidden();
    }
}
