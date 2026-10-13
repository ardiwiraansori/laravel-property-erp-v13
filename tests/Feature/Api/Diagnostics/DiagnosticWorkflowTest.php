<?php

namespace Tests\Feature\Api\Diagnostics;

use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\WithVerifiedPlatformMfa;
use Tests\TestCase;

final class DiagnosticWorkflowTest extends TestCase
{
    use RefreshDatabase, WithVerifiedPlatformMfa;

    private function company(string $code = 'TENANT-A'): Company
    {
        return Company::query()->create(['code' => $code, 'name' => 'Test '.$code]);
    }

    private function platformAdmin(bool $verified = true): User
    {
        $user = User::factory()->create();
        PlatformAdmin::query()->create(['user_id' => $user->id, 'is_active' => true, 'granted_at' => now()]);
        if ($verified) {
            $this->withVerifiedPlatformMfa($user);
        }
        return $user;
    }

    private function report(Company $company, ?User $reporter = null): string
    {
        $reporter ??= User::factory()->create();
        $reporter->companies()->syncWithoutDetaching([$company->id]);
        $id = (string) Str::ulid();
        DB::table('diagnostic_reports')->insert([
            'public_id' => $id,
            'company_id' => $company->id,
            'reporter_user_id' => $reporter->id,
            'category' => 'page_error', 'module' => 'dashboard',
            'impact' => 'partial', 'error_kind' => 'load_failed',
            'browser_family' => 'chrome', 'viewport' => 'desktop',
            'status' => 'new', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function url(string $publicId, string $action): string
    {
        return '/api/platform/diagnostics/reports/'.$publicId.'/'.$action;
    }

    public function test_guest_and_tenant_user_cannot_read_workflow_detail_or_assignees(): void
    {
        $company = $this->company();
        $id = $this->report($company);
        $this->getJson('/api/platform/diagnostics/assignees')->assertUnauthorized();
        $this->getJson('/api/platform/diagnostics/reports/'.$id)->assertUnauthorized();
        $this->patchJson($this->url($id, 'status'), ['status' => 'triaged', 'workflow_version' => 0])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/platform/diagnostics/reports/'.$id)
            ->assertForbidden();
        $this->getJson('/api/platform/diagnostics/assignees')->assertForbidden();
        $this->patchJson($this->url($id, 'status'), ['status' => 'triaged', 'workflow_version' => 0])->assertForbidden();
    }

    public function test_mfa_required_for_support_read_and_update(): void
    {
        $id = $this->report($this->company());
        $this->actingAs($this->platformAdmin(false));
        $this->getJson('/api/platform/diagnostics/reports/'.$id)->assertStatus(428);
        $this->getJson('/api/platform/diagnostics/assignees')->assertStatus(428);
        $this->patchJson($this->url($id, 'status'), ['status' => 'triaged', 'workflow_version' => 0])
            ->assertStatus(428);
    }

    public function test_writes_locked_by_default_even_after_verified_mfa(): void
    {
        $id = $this->report($this->company());
        $this->actingAs($this->platformAdmin());
        $this->getJson('/api/platform/diagnostics/reports/'.$id)->assertOk();
        $this->patchJson($this->url($id, 'status'), ['status' => 'triaged', 'workflow_version' => 0])
            ->assertStatus(423)->assertJsonPath('code', 'PLATFORM_WRITE_LOCKED');
        $this->patchJson($this->url($id, 'assignment'), ['assignee_user_id' => null, 'workflow_version' => 0])
            ->assertStatus(423);
        $this->assertDatabaseHas('diagnostic_reports', ['public_id' => $id, 'status' => 'new']);
        $this->assertDatabaseCount('diagnostic_report_events', 0);
    }

    public function test_approved_transition_creates_atomic_history_and_audit(): void
    {
        config()->set('platform.mutations_enabled', true);
        $company = $this->company();
        $id = $this->report($company);
        $actor = $this->platformAdmin();
        $this->actingAs($actor)->patchJson($this->url($id, 'status'), [
            'status' => 'triaged', 'workflow_version' => 0, 'description' => 'DO-NOT-STORE-PRIVATE',
        ])->assertOk()->assertJsonPath('data.status', 'triaged')
            ->assertJsonPath('data.workflow_version', 1)->assertDontSee('DO-NOT-STORE-PRIVATE');
        $this->assertDatabaseHas('diagnostic_report_events', [
            'company_id' => $company->id, 'actor_user_id' => $actor->id,
            'action' => 'status_changed', 'from_status' => 'new', 'to_status' => 'triaged',
        ]);
        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_user_id' => $actor->id, 'company_id' => $company->id,
            'action' => 'platform.diagnostics.status.change', 'target_type' => 'diagnostic_report',
        ]);
        $this->assertDatabaseCount('diagnostic_report_events', 1);
    }

    public function test_server_rejects_invalid_transition_and_does_not_write_audit(): void
    {
        config()->set('platform.mutations_enabled', true);
        $id = $this->report($this->company());
        $this->actingAs($this->platformAdmin());
        $this->patchJson($this->url($id, 'status'), ['status' => 'closed', 'workflow_version' => 0])
            ->assertUnprocessable();
        $this->patchJson($this->url($id, 'status'), ['status' => 'INVALID', 'workflow_version' => 0])
            ->assertUnprocessable();
        $this->patchJson($this->url($id, 'status'), ['status' => 'triaged'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('diagnostic_report_events', 0);
        $this->assertDatabaseHas('diagnostic_reports', ['public_id' => $id, 'workflow_version' => 0]);
    }

    public function test_status_lifecycle_supports_review_resolution_closure_and_reopening(): void
    {
        config()->set('platform.mutations_enabled', true);
        $id = $this->report($this->company());
        $this->actingAs($this->platformAdmin());
        foreach (['triaged', 'in_progress', 'resolved', 'closed', 'triaged'] as $version => $status) {
            $this->patchJson($this->url($id, 'status'), [
                'status' => $status, 'workflow_version' => $version,
            ])->assertOk()->assertJsonPath('data.workflow_version', $version + 1);
        }
        $this->assertDatabaseCount('diagnostic_report_events', 5);
        $this->assertDatabaseHas('diagnostic_reports', ['public_id' => $id, 'status' => 'triaged']);
    }

    public function test_stale_workflow_version_returns_conflict_without_overwrite(): void
    {
        config()->set('platform.mutations_enabled', true);
        $id = $this->report($this->company());
        $this->actingAs($this->platformAdmin());
        $this->patchJson($this->url($id, 'status'), ['status' => 'triaged', 'workflow_version' => 0])->assertOk();
        $this->patchJson($this->url($id, 'status'), ['status' => 'in_progress', 'workflow_version' => 0])
            ->assertStatus(409);
        $this->assertDatabaseHas('diagnostic_reports', ['public_id' => $id, 'status' => 'triaged', 'workflow_version' => 1]);
        $this->assertDatabaseCount('diagnostic_report_events', 1);
    }

    public function test_assignee_must_be_active_tenant_free_platform_admin(): void
    {
        config()->set('platform.mutations_enabled', true);
        $id = $this->report($this->company());
        $actor = $this->platformAdmin();
        $ordinary = User::factory()->create();
        $this->actingAs($actor)->patchJson($this->url($id, 'assignment'), [
            'assignee_user_id' => $ordinary->id, 'workflow_version' => 0,
        ])->assertUnprocessable();
        $this->patchJson($this->url($id, 'assignment'), ['assignee_user_id' => 999999, 'workflow_version' => 0])
            ->assertUnprocessable();
        $this->assertDatabaseCount('diagnostic_report_events', 0);
    }

    public function test_assignee_can_be_set_and_cleared_with_history_and_audit(): void
    {
        config()->set('platform.mutations_enabled', true);
        $company = $this->company();
        $id = $this->report($company);
        $assignee = $this->platformAdmin(false);
        $actor = $this->platformAdmin();
        $this->actingAs($actor)->patchJson($this->url($id, 'assignment'), [
            'assignee_user_id' => $assignee->id, 'workflow_version' => 0,
        ])->assertOk()->assertJsonPath('data.assigned_platform_admin_user_id', $assignee->id);
        $this->patchJson($this->url($id, 'assignment'), [
            'assignee_user_id' => null, 'workflow_version' => 1,
        ])->assertOk()->assertJsonPath('data.assigned_platform_admin_user_id', null);
        $this->assertDatabaseHas('diagnostic_report_events', [
            'action' => 'assignment_changed', 'to_assignee_user_id' => $assignee->id,
        ]);
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'platform.diagnostics.assignment.change', 'company_id' => $company->id,
        ]);
        $this->assertDatabaseCount('diagnostic_report_events', 2);
    }

    public function test_revoked_or_tenant_member_platform_grant_is_not_eligible_for_assignment(): void
    {
        config()->set('platform.mutations_enabled', true);
        $company = $this->company();
        $id = $this->report($company);
        $revoked = $this->platformAdmin(false);
        $revoked->platformAdmin()->update(['revoked_at' => now()]);
        $tenantMember = $this->platformAdmin(false);
        $tenantMember->companies()->attach($company->id);
        $this->actingAs($this->platformAdmin());
        foreach ([$revoked, $tenantMember] as $ineligible) {
            $this->patchJson($this->url($id, 'assignment'), [
                'assignee_user_id' => $ineligible->id, 'workflow_version' => 0,
            ])->assertUnprocessable();
        }
        $assignees = $this->getJson('/api/platform/diagnostics/assignees')->assertOk()->json('data');
        $ids = array_column($assignees, 'id');
        $this->assertNotContains($revoked->id, $ids);
        $this->assertNotContains($tenantMember->id, $ids);
    }

    public function test_detail_returns_only_allowlisted_history_and_records_read_audit(): void
    {
        config()->set('platform.mutations_enabled', true);
        $id = $this->report($this->company());
        $admin = $this->platformAdmin();
        $this->actingAs($admin)->patchJson($this->url($id, 'status'), [
            'status' => 'triaged', 'workflow_version' => 0,
        ])->assertOk();
        $response = $this->getJson('/api/platform/diagnostics/reports/'.$id)
            ->assertOk()->assertJsonCount(1, 'history')
            ->assertJsonPath('history.0.action', 'status_changed')
            ->assertJsonPath('data.workflow_version', 1);
        $this->assertSame([
            'action', 'actor_user_id', 'from_status', 'to_status',
            'from_assignee_user_id', 'to_assignee_user_id', 'created_at',
        ], array_keys($response->json('history.0')));
        $response->assertDontSee('email')->assertDontSee('password')->assertDontSee('payload');
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'platform.diagnostics.detail.read', 'actor_user_id' => $admin->id,
        ]);
    }

    public function test_tenant_header_blocks_all_platform_workflow_routes(): void
    {
        config()->set('platform.mutations_enabled', true);
        $id = $this->report($this->company());
        $this->actingAs($this->platformAdmin())->withHeader('X-Company-ID', '1');
        $this->getJson('/api/platform/diagnostics/reports/'.$id)->assertStatus(422);
        $this->getJson('/api/platform/diagnostics/assignees')->assertStatus(422);
        $this->patchJson($this->url($id, 'status'), [
            'status' => 'triaged', 'workflow_version' => 0,
        ])->assertStatus(422);
        $this->assertDatabaseCount('diagnostic_report_events', 0);
    }

    public function test_tenant_read_api_stays_scoped_and_exposes_only_status_not_support_history(): void
    {
        config()->set('platform.mutations_enabled', true);
        $a = $this->company('A');
        $b = $this->company('B');
        $user = User::factory()->create();
        $user->companies()->attach([$a->id, $b->id]);
        $idA = $this->report($a, $user);
        $idB = $this->report($b, $user);
        $this->actingAs($this->platformAdmin())->patchJson($this->url($idA, 'status'), [
            'status' => 'triaged', 'workflow_version' => 0,
        ])->assertOk();
        $this->actingAs($user)->withHeader('X-Company-ID', (string) $a->id)
            ->getJson('/api/diagnostics/reports')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $idA)
            ->assertJsonPath('data.0.status', 'triaged')->assertDontSee($idB)
            ->assertDontSee('assigned_platform_admin_user_id')->assertDontSee('actor_user_id');
        $this->withHeader('X-Company-ID', (string) $b->id)
            ->getJson('/api/diagnostics/reports')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $idB)->assertDontSee($idA);
    }

    public function test_status_in_progress_can_be_filtered_in_platform_directory(): void
    {
        config()->set('platform.mutations_enabled', true);
        $id = $this->report($this->company());
        $this->actingAs($this->platformAdmin());
        $this->patchJson($this->url($id, 'status'), ['status' => 'triaged', 'workflow_version' => 0])->assertOk();
        $this->patchJson($this->url($id, 'status'), ['status' => 'in_progress', 'workflow_version' => 1])->assertOk();
        $this->getJson('/api/platform/diagnostics/reports?status=in_progress')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $id)
            ->assertJsonPath('data.0.workflow_version', 2);
        $this->getJson('/api/platform/diagnostics/reports?status=closed')->assertJsonCount(0, 'data');
    }

    public function test_assignment_rejects_noop_and_missing_version(): void
    {
        config()->set('platform.mutations_enabled', true);
        $id = $this->report($this->company());
        $this->actingAs($this->platformAdmin());
        $this->patchJson($this->url($id, 'assignment'), [
            'assignee_user_id' => null, 'workflow_version' => 0,
        ])->assertUnprocessable();
        $this->patchJson($this->url($id, 'assignment'), [
            'assignee_user_id' => null,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('diagnostic_report_events', 0);
    }
}
