<?php

namespace Tests\Feature\Api\Platform;

use App\Http\Middleware\EnsurePlatformWriteEnabled;
use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Concerns\WithVerifiedPlatformMfa;

final class PlatformManagementTest extends TestCase
{
    use RefreshDatabase, WithVerifiedPlatformMfa;

    private function admin(): User
    {
        $user = User::factory()->create();
        PlatformAdmin::query()->create([
            'user_id' => $user->id,
            'granted_at' => now(),
            'is_active' => true,
        ]);
        $this->withVerifiedPlatformMfa($user);
        return $user;
    }

    private function company(string $code = 'TENANT-01'): Company
    {
        return Company::query()->create(['code' => $code, 'name' => 'Company '.$code]);
    }

    public function test_platform_writes_are_locked_by_default_even_for_real_admin(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)
            ->getJson('/api/platform/capabilities')
            ->assertOk()->assertJsonPath('data.writes_enabled', false);
        $this->postJson('/api/platform/companies', ['code' => 'NEW-01', 'name' => 'New company'])
            ->assertStatus(423)->assertJsonPath('code', 'PLATFORM_WRITE_LOCKED');
        $this->patchJson('/api/platform/users/'.$admin->id, ['name' => 'Changed'])
            ->assertStatus(423);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_configuration_flag_alone_cannot_unlock_writes_without_mfa_proof(): void
    {
        config()->set('platform.mutations_enabled', true);
        $this->actingAs($this->admin());
        $this->withSession(['platform_mfa_verified' => false]);
        $this->getJson('/api/platform/capabilities')->assertStatus(428);
        $this->postJson('/api/platform/companies', ['code' => 'NEW-01', 'name' => 'New company'])
            ->assertStatus(428);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_guest_and_tenant_member_cannot_access_platform_capabilities(): void
    {
        $this->getJson('/api/platform/capabilities')->assertUnauthorized();
        $user = User::factory()->create();
        $user->companies()->attach($this->company()->id);
        $this->actingAs($user)->getJson('/api/platform/capabilities')->assertForbidden();
        $this->postJson('/api/platform/companies', ['code' => 'NEW-01', 'name' => 'New company'])
            ->assertForbidden();
    }

    public function test_tenant_header_is_rejected_before_platform_write_guard(): void
    {
        $this->actingAs($this->admin())->withHeader('X-Company-ID', '1')
            ->postJson('/api/platform/companies', ['code' => 'NEW-01', 'name' => 'New company'])
            ->assertStatus(422);
    }

    public function test_internal_business_logic_creates_company_with_atomic_audit(): void
    {
        $this->withoutMiddleware(EnsurePlatformWriteEnabled::class);
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/platform/companies', [
            'code' => 'NEW-01', 'name' => 'New company',
        ])->assertCreated()->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('companies', ['code' => 'NEW-01']);
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'platform.company.create', 'actor_user_id' => $admin->id,
        ]);
        $this->postJson('/api/platform/companies', [
            'code' => 'NEW-01', 'name' => 'Duplicate company',
        ])->assertUnprocessable();
    }

    public function test_internal_company_update_preserves_immutable_code(): void
    {
        $this->withoutMiddleware(EnsurePlatformWriteEnabled::class);
        $company = $this->company();
        $this->actingAs($this->admin())->patchJson('/api/platform/companies/'.$company->id, [
            'name' => 'Renamed', 'is_active' => false, 'code' => 'TAMPER',
        ])->assertOk()->assertJsonPath('data.code', 'TENANT-01');
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'code' => 'TENANT-01', 'is_active' => false]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform.company.update']);
    }

    public function test_internal_user_name_update_does_not_modify_email_or_tenant_access(): void
    {
        $this->withoutMiddleware(EnsurePlatformWriteEnabled::class);
        $user = User::factory()->create(['email' => 'member@example.com']);
        $company = $this->company();
        $user->companies()->attach($company->id);
        $this->actingAs($this->admin())->patchJson('/api/platform/users/'.$user->id, [
            'name' => 'Updated Name', 'email' => 'tampered@example.com',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Name');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'member@example.com']);
        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $company->id]);
    }

    public function test_membership_can_be_attached_and_detached_without_tenant_role_grants(): void
    {
        $this->withoutMiddleware(EnsurePlatformWriteEnabled::class);
        $user = User::factory()->create();
        $company = $this->company();
        $this->actingAs($this->admin());
        $endpoint = '/api/platform/users/'.$user->id.'/memberships/'.$company->id;
        $this->postJson($endpoint)->assertCreated();
        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $company->id]);
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $user->id)->count());
        $this->postJson($endpoint)->assertStatus(409);
        $this->deleteJson($endpoint)->assertOk();
        $this->assertDatabaseMissing('company_user', ['user_id' => $user->id, 'company_id' => $company->id]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform.membership.attach']);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform.membership.detach']);
    }

    public function test_platform_identity_cannot_be_added_as_tenant_member(): void
    {
        $this->withoutMiddleware(EnsurePlatformWriteEnabled::class);
        $admin = $this->admin();
        $company = $this->company();
        $this->actingAs($admin)->postJson('/api/platform/users/'.$admin->id.'/memberships/'.$company->id)
            ->assertStatus(409);
        $this->assertDatabaseCount('company_user', 0);
    }

    public function test_inactive_company_does_not_accept_new_membership(): void
    {
        $this->withoutMiddleware(EnsurePlatformWriteEnabled::class);
        $company = Company::query()->create(['code' => 'INACTIVE', 'name' => 'Inactive Co', 'is_active' => false]);
        $member = User::factory()->create();
        $this->actingAs($this->admin())->postJson('/api/platform/users/'.$member->id.'/memberships/'.$company->id)
            ->assertStatus(409);
    }

    public function test_cli_bootstrap_is_preview_only_without_explicit_execute(): void
    {
        User::factory()->create(['email' => 'dedicated-admin@example.com']);
        $this->artisan('platform:bootstrap-admin', ['email' => 'dedicated-admin@example.com'])
            ->assertSuccessful();
        $this->assertDatabaseCount('platform_admins', 0);
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_cli_bootstrap_rejects_tenant_membership(): void
    {
        $user = User::factory()->create(['email' => 'tenant-admin@example.com']);
        $user->companies()->attach($this->company()->id);
        $this->artisan('platform:bootstrap-admin', ['email' => $user->email])
            ->assertFailed();
        $this->assertDatabaseCount('platform_admins', 0);
    }
}
