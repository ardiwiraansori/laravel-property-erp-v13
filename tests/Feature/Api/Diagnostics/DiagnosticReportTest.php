<?php

namespace Tests\Feature\Api\Diagnostics;

use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithVerifiedPlatformMfa;
use Tests\TestCase;

final class DiagnosticReportTest extends TestCase
{
    use RefreshDatabase, WithVerifiedPlatformMfa;

    private function company(string $code = 'TENANT-A', bool $active = true): Company
    {
        return Company::query()->create([
            'code' => $code, 'name' => 'Tenant '.$code, 'is_active' => $active,
        ]);
    }

    private function reporter(Company $company): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company->id);
        return $user;
    }

    private function payload(): array
    {
        return [
            'category' => 'page_error',
            'module' => 'dashboard',
            'impact' => 'partial',
            'error_kind' => 'load_failed',
            'browser_family' => 'edge',
            'viewport' => 'desktop',
        ];
    }

    private function send(User $user, Company $company, ?array $payload = null)
    {
        return $this->actingAs($user)->withHeader('X-Company-ID', (string) $company->id)
            ->postJson('/api/diagnostics/reports', $payload ?? $this->payload());
    }

    private function platformAdmin(bool $mfa = true): User
    {
        $user = User::factory()->create();
        PlatformAdmin::query()->create([
            'user_id' => $user->id, 'is_active' => true, 'granted_at' => now(),
        ]);
        if ($mfa) {
            $this->withVerifiedPlatformMfa($user);
        }
        return $user;
    }

    public function test_guest_cannot_submit_or_list_reports(): void
    {
        $this->postJson('/api/diagnostics/reports', $this->payload())->assertUnauthorized();
        $this->getJson('/api/diagnostics/reports')->assertUnauthorized();
        $this->assertDatabaseCount('diagnostic_reports', 0);
    }

    public function test_company_header_and_membership_are_mandatory(): void
    {
        $company = $this->company();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/diagnostics/reports', $this->payload())->assertStatus(422);
        $this->withHeader('X-Company-ID', (string) $company->id)
            ->postJson('/api/diagnostics/reports', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('diagnostic_reports', 0);
    }

    public function test_active_tenant_member_can_submit_structured_report(): void
    {
        $company = $this->company();
        $user = $this->reporter($company);
        $response = $this->send($user, $company)->assertCreated()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.category', 'page_error');
        $this->assertSame(26, strlen($response->json('data.public_id')));
        $this->assertDatabaseHas('diagnostic_reports', [
            'company_id' => $company->id, 'reporter_user_id' => $user->id,
            'module' => 'dashboard', 'category' => 'page_error', 'status' => 'new',
        ]);
    }

    public function test_unapproved_or_malformed_values_are_rejected(): void
    {
        $company = $this->company();
        $user = $this->reporter($company);
        $invalid = array_replace($this->payload(), [
            'module' => 'private_consumer_ledger', 'browser_family' => 'Mozilla full raw UA',
        ]);
        $this->send($user, $company, $invalid)->assertUnprocessable()
            ->assertJsonValidationErrors(['module', 'browser_family']);
        $this->assertDatabaseCount('diagnostic_reports', 0);
    }

    public function test_extra_sensitive_fields_are_not_stored_or_returned(): void
    {
        $company = $this->company();
        $user = $this->reporter($company);
        $sensitive = 'DO-NOT-STORE-TOKEN-ACCOUNT-123456';
        $input = array_replace($this->payload(), [
            'url' => '/customers/123?token='.$sensitive,
            'screenshot' => $sensitive,
            'description' => $sensitive,
            'cookie' => $sensitive,
            'company_id' => 999999,
            'reporter_user_id' => 999999,
        ]);
        $this->send($user, $company, $input)->assertCreated()->assertDontSee($sensitive);
        $row = DB::table('diagnostic_reports')->first();
        $this->assertSame($company->id, $row->company_id);
        $this->assertSame($user->id, $row->reporter_user_id);
        $this->assertFalse(property_exists($row, 'description'));
        $this->assertFalse(property_exists($row, 'url'));
        $this->actingAs($user)->withHeader('X-Company-ID', (string) $company->id)
            ->getJson('/api/diagnostics/reports')->assertOk()->assertDontSee($sensitive);
    }

    public function test_reports_are_isolated_across_tenants_for_same_user(): void
    {
        $first = $this->company('A');
        $second = $this->company('B');
        $user = $this->reporter($first);
        $user->companies()->attach($second->id);
        $a = $this->send($user, $first)->assertCreated()->json('data.public_id');
        $b = $this->send($user, $second, array_replace($this->payload(), ['module' => 'crm']))
            ->assertCreated()->json('data.public_id');
        $this->withHeader('X-Company-ID', (string) $first->id)
            ->getJson('/api/diagnostics/reports')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $a)->assertDontSee($b);
        $this->withHeader('X-Company-ID', (string) $second->id)
            ->getJson('/api/diagnostics/reports')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $b)->assertDontSee($a);
        $this->withHeader('X-Company-ID', (string) $first->id)
            ->getJson('/api/diagnostics/reports')->assertJsonPath('data.0.public_id', $a);
    }

    public function test_users_in_same_tenant_cannot_read_each_others_reports(): void
    {
        $company = $this->company();
        $a = $this->reporter($company);
        $b = $this->reporter($company);
        $id = $this->send($a, $company)->assertCreated()->json('data.public_id');
        $this->actingAs($b)->withHeader('X-Company-ID', (string) $company->id)
            ->getJson('/api/diagnostics/reports')->assertOk()->assertJsonCount(0, 'data')
            ->assertDontSee($id);
    }

    public function test_inactive_company_cannot_accept_reports(): void
    {
        $company = $this->company('INACTIVE', false);
        $this->send($this->reporter($company), $company)->assertForbidden();
        $this->assertDatabaseCount('diagnostic_reports', 0);
    }

    public function test_reports_are_rate_limited_per_reporter(): void
    {
        $company = $this->company();
        $user = $this->reporter($company);
        for ($i = 0; $i < 5; $i++) {
            $this->send($user, $company)->assertCreated();
        }
        $this->send($user, $company)->assertStatus(429);
        $this->assertDatabaseCount('diagnostic_reports', 5);
    }

    public function test_platform_directory_denies_guests_and_tenant_members(): void
    {
        $this->getJson('/api/platform/diagnostics/reports')->assertUnauthorized();
        $company = $this->company();
        $this->actingAs($this->reporter($company))->getJson('/api/platform/diagnostics/reports')
            ->assertForbidden();
    }

    public function test_platform_directory_requires_mfa_and_rejects_tenant_header(): void
    {
        $admin = $this->platformAdmin(false);
        $this->actingAs($admin)->getJson('/api/platform/diagnostics/reports')->assertStatus(428);
        $this->withHeader('X-Company-ID', '1')->getJson('/api/platform/diagnostics/reports')
            ->assertStatus(422);
    }

    public function test_platform_receives_only_allowlisted_metadata_with_audit(): void
    {
        $company = $this->company();
        $reporter = $this->reporter($company);
        $publicId = $this->send($reporter, $company)->assertCreated()->json('data.public_id');
        $admin = $this->platformAdmin();
        $this->withoutHeader('X-Company-ID');
        $this->actingAs($admin)->getJson('/api/platform/diagnostics/reports')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.public_id', $publicId)
            ->assertJsonPath('data.0.company_code', 'TENANT-A')
            ->assertJsonPath('data.0.reporter_user_id', $reporter->id)
            ->assertDontSee($reporter->email);
        $this->assertSame([
            'public_id', 'company_id', 'company_code', 'reporter_user_id', 'category',
            'module', 'impact', 'error_kind', 'browser_family', 'viewport', 'status',
            'assigned_platform_admin_user_id', 'workflow_version', 'created_at', 'updated_at',
        ], array_keys($this->getJson('/api/platform/diagnostics/reports')->json('data.0')));
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'platform.diagnostics.read', 'actor_user_id' => $admin->id,
        ]);
    }

    public function test_platform_filters_company_but_cannot_access_tenant_context(): void
    {
        $a = $this->company('A');
        $b = $this->company('B');
        $idA = $this->send($this->reporter($a), $a)->assertCreated()->json('data.public_id');
        $idB = $this->send($this->reporter($b), $b)->assertCreated()->json('data.public_id');
        $admin = $this->platformAdmin();
        $this->withoutHeader('X-Company-ID');
        $this->actingAs($admin)->getJson('/api/platform/diagnostics/reports?company_id='.$a->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $idA)
            ->assertDontSee($idB);
        $this->withHeader('X-Company-ID', (string) $b->id)
            ->getJson('/api/company-context')->assertForbidden();
    }

    public function test_platform_directory_validates_filters_and_is_read_only(): void
    {
        $this->actingAs($this->platformAdmin());
        $this->getJson('/api/platform/diagnostics/reports?per_page=200')->assertUnprocessable();
        $this->getJson('/api/platform/diagnostics/reports?status=SECRET')->assertUnprocessable();
        $this->postJson('/api/platform/diagnostics/reports', ['status' => 'closed'])
            ->assertStatus(405);
    }
}
