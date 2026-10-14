<?php

namespace Tests\Feature\Api\Diagnostics;

use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\WithVerifiedPlatformMfa;
use Tests\TestCase;

/** Checkpoint 7.12: read-only 30-day workflow funnel and timing measurements. */
final class DiagnosticWorkflowPerformanceTest extends TestCase
{
    use RefreshDatabase, WithVerifiedPlatformMfa;

    private const URL = '/api/platform/diagnostics/reports';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
    }

    private function company(string $code = 'TENANT-A'): Company
    {
        return Company::query()->create(['code' => $code, 'name' => 'Test '.$code]);
    }

    private function admin(bool $verified = true): User
    {
        $user = User::factory()->create();
        PlatformAdmin::query()->create(['user_id' => $user->id, 'is_active' => true, 'granted_at' => now()]);
        if ($verified) {
            $this->withVerifiedPlatformMfa($user);
        }
        return $user;
    }

    private function report(Company $company, Carbon $created, string $category = 'page_error',
        string $impact = 'partial', string $status = 'new', ?User $assignee = null): int
    {
        $reporter = User::factory()->create();
        $reporter->companies()->attach($company->id);
        return (int) DB::table('diagnostic_reports')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $company->id,
            'reporter_user_id' => $reporter->id,
            'category' => $category,
            'module' => 'dashboard',
            'impact' => $impact,
            'error_kind' => 'load_failed',
            'browser_family' => 'edge',
            'viewport' => 'desktop',
            'status' => $status,
            'assigned_platform_admin_user_id' => $assignee?->id,
            'created_at' => $created,
            'updated_at' => $created,
        ]);
    }

    private function statusEvent(int $reportId, Company $company, User $actor,
        string $from, string $to, Carbon $at): void
    {
        DB::table('diagnostic_report_events')->insert([
            'diagnostic_report_id' => $reportId,
            'company_id' => $company->id,
            'actor_user_id' => $actor->id,
            'action' => 'status_changed',
            'from_status' => $from,
            'to_status' => $to,
            'created_at' => $at,
        ]);
    }

    public function test_empty_queue_has_explicit_zero_counts_and_null_averages(): void
    {
        $this->actingAs($this->admin());
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('workflow_performance.window_days', 30)
            ->assertJsonPath('workflow_performance.reported', 0)
            ->assertJsonPath('workflow_performance.triaged', 0)
            ->assertJsonPath('workflow_performance.resolved', 0)
            ->assertJsonPath('workflow_performance.reopened', 0)
            ->assertJsonPath('workflow_performance.awaiting_first_triage', 0)
            ->assertJsonPath('workflow_performance.average_hours_to_first_triage', null)
            ->assertJsonPath('workflow_performance.average_hours_to_first_resolution', null);
    }

    public function test_first_event_averages_ignore_later_retriage_and_reresolution(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $firstAt = now()->subDays(10);
        $first = $this->report($company, $firstAt, status: 'resolved');
        $this->statusEvent($first, $company, $actor, 'new', 'triaged', $firstAt->copy()->addHours(2));
        $this->statusEvent($first, $company, $actor, 'triaged', 'in_progress', $firstAt->copy()->addHours(3));
        $this->statusEvent($first, $company, $actor, 'in_progress', 'resolved', $firstAt->copy()->addHours(10));
        $this->statusEvent($first, $company, $actor, 'resolved', 'in_progress', $firstAt->copy()->addHours(12));
        $this->statusEvent($first, $company, $actor, 'in_progress', 'resolved', $firstAt->copy()->addHours(22));

        $secondAt = now()->subDays(5);
        $second = $this->report($company, $secondAt, status: 'resolved');
        $this->statusEvent($second, $company, $actor, 'new', 'triaged', $secondAt->copy()->addHours(4));
        $this->statusEvent($second, $company, $actor, 'in_progress', 'resolved', $secondAt->copy()->addHours(12));
        $this->report($company, now()->subDay());

        $this->actingAs($actor)->getJson(self::URL)->assertOk()
            ->assertJsonPath('workflow_performance.reported', 3)
            ->assertJsonPath('workflow_performance.triaged', 2)
            ->assertJsonPath('workflow_performance.resolved', 2)
            ->assertJsonPath('workflow_performance.reopened', 1)
            ->assertJsonPath('workflow_performance.awaiting_first_triage', 1)
            ->assertJsonPath('workflow_performance.average_hours_to_first_triage', 3)
            ->assertJsonPath('workflow_performance.average_hours_to_first_resolution', 11);
    }

    public function test_inclusive_thirty_day_cohort_excludes_older_and_future_reports(): void
    {
        $company = $this->company();
        $this->report($company, now()->subDays(30));
        $this->report($company, now()->subDays(30)->subSecond());
        $this->report($company, now()->addSecond());
        $this->actingAs($this->admin());
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('workflow_performance.reported', 1)
            ->assertJsonPath('workflow_performance.awaiting_first_triage', 1)
            ->assertJsonPath('summary.total', 3);
    }

    public function test_reopening_count_is_distinct_per_report_and_ignores_retriage(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $at = now()->subDays(4);
        $id = $this->report($company, $at, status: 'triaged');
        $this->statusEvent($id, $company, $actor, 'new', 'triaged', $at->copy()->addHour());
        $this->statusEvent($id, $company, $actor, 'resolved', 'in_progress', $at->copy()->addHours(2));
        $this->statusEvent($id, $company, $actor, 'in_progress', 'triaged', $at->copy()->addHours(3));
        $this->statusEvent($id, $company, $actor, 'closed', 'triaged', $at->copy()->addHours(4));
        $this->actingAs($actor)->getJson(self::URL)->assertOk()
            ->assertJsonPath('workflow_performance.reopened', 1)
            ->assertJsonPath('workflow_performance.triaged', 1)
            ->assertJsonPath('workflow_performance.resolved', 0);
    }

    public function test_company_category_and_impact_scope_metrics_without_leaking_other_tenants(): void
    {
        $companyA = $this->company('A');
        $companyB = $this->company('B');
        $actor = $this->admin();
        $a = $this->report($companyA, now()->subDays(2), 'page_error', 'blocked');
        $this->statusEvent($a, $companyA, $actor, 'new', 'triaged', now()->subDays(2)->addHour());
        $this->report($companyA, now()->subDay(), 'other', 'blocked');
        $this->report($companyA, now()->subDay(), 'page_error', 'minor');
        $this->report($companyB, now()->subDay(), 'page_error', 'blocked');
        $this->actingAs($actor);
        $this->getJson(self::URL.'?company_id='.$companyA->id.'&category=page_error&impact=blocked')
            ->assertOk()
            ->assertJsonPath('workflow_performance.reported', 1)
            ->assertJsonPath('workflow_performance.triaged', 1)
            ->assertJsonPath('summary.total', 1);
        $this->getJson(self::URL.'?company_id='.$companyB->id.'&category=page_error&impact=blocked')
            ->assertOk()
            ->assertJsonPath('workflow_performance.reported', 1)
            ->assertJsonPath('workflow_performance.triaged', 0);
    }

    public function test_table_only_filters_and_pagination_do_not_change_cohort_metrics(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $created = now()->subDays(8);
        $id = $this->report($company, $created, status: 'resolved', assignee: $actor);
        $this->statusEvent($id, $company, $actor, 'new', 'triaged', $created->copy()->addHours(2));
        $this->report($company, now()->subDay());
        $this->actingAs($actor);
        $this->getJson(self::URL.'?status=closed&assignment=unassigned&age=over_7d&inactivity=over_7d&per_page=1&page=2&sort=oldest')
            ->assertOk()->assertJsonPath('meta.total', 0)
            ->assertJsonPath('workflow_performance.reported', 2)
            ->assertJsonPath('workflow_performance.triaged', 1)
            ->assertJsonPath('workflow_performance.average_hours_to_first_triage', 2);
    }

    public function test_guest_tenant_and_unverified_platform_admin_cannot_read_metrics(): void
    {
        $company = $this->company();
        $this->report($company, now()->subDay());
        $this->getJson(self::URL)->assertUnauthorized();
        $tenant = User::factory()->create();
        $tenant->companies()->attach($company->id);
        $this->actingAs($tenant)->getJson(self::URL)->assertForbidden();
        $this->actingAs($this->admin(false))->getJson(self::URL)->assertStatus(428);
    }

    public function test_metrics_are_read_only_and_do_not_bypass_platform_write_lock(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $id = $this->report($company, now()->subDays(3));
        $eventCountBefore = DB::table('diagnostic_report_events')->count();
        $this->actingAs($actor)->getJson(self::URL)->assertOk()
            ->assertJsonPath('workflow_performance.reported', 1)
            ->assertDontSee('password')
            ->assertDontSee('access_token');
        $this->assertSame($eventCountBefore, DB::table('diagnostic_report_events')->count());
        $this->assertDatabaseHas('diagnostic_reports', ['id' => $id, 'status' => 'new']);
        $this->patchJson(self::URL.'/'.DB::table('diagnostic_reports')->where('id', $id)->value('public_id').'/status', [
            'status' => 'triaged', 'workflow_version' => 0,
        ])->assertStatus(423);
        $this->assertDatabaseCount('diagnostic_report_events', 0);
    }
}
