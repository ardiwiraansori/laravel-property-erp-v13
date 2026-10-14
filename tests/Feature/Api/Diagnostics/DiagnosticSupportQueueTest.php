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

/** Checkpoint 7.9: read-only workload insights and queue isolation. */
final class DiagnosticSupportQueueTest extends TestCase
{
    use RefreshDatabase, WithVerifiedPlatformMfa;

    private const URL = '/api/platform/diagnostics/reports';

    private function admin(bool $verified = true): User
    {
        $user = User::factory()->create();
        PlatformAdmin::query()->create(['user_id' => $user->id, 'is_active' => true, 'granted_at' => now()]);
        if ($verified) {
            $this->withVerifiedPlatformMfa($user);
        }
        return $user;
    }

    private function company(string $code): Company
    {
        return Company::query()->create(['code' => $code, 'name' => 'Test '.$code]);
    }

    private function report(Company $company, string $status = 'new', ?int $assignee = null,
        string $category = 'page_error', string $impact = 'partial'): string
    {
        $reporter = User::factory()->create();
        $reporter->companies()->attach($company->id);
        $id = (string) Str::ulid();
        DB::table('diagnostic_reports')->insert([
            'public_id' => $id,
            'company_id' => $company->id,
            'reporter_user_id' => $reporter->id,
            'category' => $category,
            'module' => 'dashboard',
            'impact' => $impact,
            'error_kind' => 'load_failed',
            'browser_family' => 'edge',
            'viewport' => 'desktop',
            'status' => $status,
            'assigned_platform_admin_user_id' => $assignee,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    public function test_summary_counts_every_status_and_assignment_without_business_metadata(): void
    {
        $company = $this->company('A');
        $actor = $this->admin();
        $other = $this->admin(false);
        $this->report($company, 'new');
        $this->report($company, 'triaged', $actor->id);
        $this->report($company, 'in_progress', $other->id);
        $this->report($company, 'resolved', $actor->id);
        $this->report($company, 'closed');
        $this->actingAs($actor);

        $response = $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('summary.total', 5)
            ->assertJsonPath('summary.unassigned', 2)
            ->assertJsonPath('summary.assigned', 3)
            ->assertJsonPath('summary.mine', 2)
            ->assertJsonPath('summary.by_status.new', 1)
            ->assertJsonPath('summary.by_status.triaged', 1)
            ->assertJsonPath('summary.by_status.in_progress', 1)
            ->assertJsonPath('summary.by_status.resolved', 1)
            ->assertJsonPath('summary.by_status.closed', 1)
            ->assertJsonCount(5, 'data');
        $this->assertSame(['total', 'by_status', 'unassigned', 'assigned', 'mine'],
            array_keys($response->json('summary')));
        $response->assertDontSee($actor->email)->assertDontSee('cookie')->assertDontSee('token');
        $this->assertDatabaseHas('platform_audit_logs', [
            'actor_user_id' => $actor->id, 'action' => 'platform.diagnostics.read',
        ]);
    }

    public function test_assignment_lanes_are_scoped_to_the_authenticated_platform_identity(): void
    {
        $company = $this->company('A');
        $actor = $this->admin();
        $other = $this->admin(false);
        $mine = $this->report($company, 'triaged', $actor->id);
        $theirs = $this->report($company, 'triaged', $other->id);
        $unassigned = $this->report($company);
        $this->actingAs($actor);

        $this->getJson(self::URL.'?assignment=mine')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.public_id', $mine)
            ->assertJsonPath('summary.total', 3)->assertJsonPath('summary.mine', 1)
            ->assertDontSee($theirs)->assertDontSee($unassigned);
        $this->getJson(self::URL.'?assignment=unassigned')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.public_id', $unassigned);
        $this->getJson(self::URL.'?assignment=assigned')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertDontSee($unassigned);
        $this->getJson(self::URL.'?assignment=all')->assertJsonPath('meta.total', 3);
    }

    public function test_status_filter_does_not_change_unfiltered_workload_summary(): void
    {
        $company = $this->company('A');
        $this->report($company, 'new');
        $this->report($company, 'closed');
        $this->actingAs($this->admin());

        $this->getJson(self::URL.'?status=closed')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'closed')
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.by_status.new', 1)
            ->assertJsonPath('summary.by_status.closed', 1);
    }

    public function test_company_category_and_impact_filters_apply_to_summary_and_data(): void
    {
        $a = $this->company('A');
        $b = $this->company('B');
        $wanted = $this->report($a, 'new', null, 'page_error', 'blocked');
        $otherCategory = $this->report($a, 'closed', null, 'access_problem', 'blocked');
        $otherImpact = $this->report($a, 'triaged', null, 'page_error', 'minor');
        $otherTenant = $this->report($b, 'new', null, 'page_error', 'blocked');
        $this->actingAs($this->admin());

        $this->getJson(self::URL.'?company_id='.$a->id.'&category=page_error&impact=blocked')
            ->assertOk()->assertJsonPath('summary.total', 1)
            ->assertJsonPath('data.0.public_id', $wanted)
            ->assertDontSee($otherCategory)->assertDontSee($otherImpact)->assertDontSee($otherTenant);
        $this->getJson(self::URL.'?company_id='.$b->id)->assertOk()
            ->assertJsonPath('summary.total', 1)->assertJsonPath('data.0.public_id', $otherTenant);
    }

    public function test_zero_results_have_zeroed_summary_and_stable_pagination(): void
    {
        $this->actingAs($this->admin());
        $this->getJson(self::URL.'?assignment=mine')->assertOk()
            ->assertJsonPath('summary.total', 0)
            ->assertJsonPath('summary.mine', 0)
            ->assertJsonPath('summary.unassigned', 0)
            ->assertJsonPath('summary.assigned', 0)
            ->assertJsonPath('summary.by_status.new', 0)
            ->assertJsonPath('summary.by_status.closed', 0)
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_filter_values_are_allowlisted_and_pagination_bounded(): void
    {
        $this->actingAs($this->admin());
        foreach (['assignment=3', 'assignment=someones_email', 'impact=critical',
            'category=token', 'status=waiting', 'per_page=51', 'page=0'] as $filter) {
            $this->getJson(self::URL.'?'.$filter)->assertUnprocessable();
        }
        $this->assertDatabaseCount('diagnostic_reports', 0);
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_guest_tenant_and_unverified_platform_user_cannot_access_summary(): void
    {
        $this->getJson(self::URL.'?assignment=mine')->assertUnauthorized();
        $tenant = User::factory()->create();
        $tenant->companies()->attach($this->company('A')->id);
        $this->actingAs($tenant)->getJson(self::URL.'?assignment=mine')->assertForbidden();
        $this->actingAs($this->admin(false))->getJson(self::URL)->assertStatus(428);
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_platform_request_rejects_tenant_header_and_does_not_write_diagnostics(): void
    {
        $company = $this->company('A');
        $id = $this->report($company);
        $this->actingAs($this->admin())->withHeader('X-Company-ID', (string) $company->id);
        $this->getJson(self::URL.'?assignment=mine')->assertStatus(422);
        $this->assertDatabaseHas('diagnostic_reports', ['public_id' => $id, 'status' => 'new']);
        $this->assertDatabaseCount('diagnostic_report_events', 0);
    }

    public function test_pagination_changes_only_rows_not_queue_summary(): void
    {
        $company = $this->company('A');
        for ($i = 0; $i < 3; $i++) {
            $this->report($company);
        }
        $this->actingAs($this->admin());
        $first = $this->getJson(self::URL.'?per_page=1&page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('summary.total', 3)
            ->assertJsonPath('meta.last_page', 3)->json('data.0.public_id');
        $this->getJson(self::URL.'?per_page=1&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('summary.total', 3)
            ->assertJsonPath('meta.current_page', 2)->assertDontSee($first);
    }
}
