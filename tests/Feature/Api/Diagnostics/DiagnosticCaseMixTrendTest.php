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

/** Checkpoint 7.13: server-scoped, metadata-only case mix and seven-date intake. */
final class DiagnosticCaseMixTrendTest extends TestCase
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

    private function report(Company $company, Carbon $created,
        string $module = 'dashboard', string $category = 'page_error',
        string $impact = 'partial', string $status = 'new', ?int $assignee = null): int
    {
        $reporter = User::factory()->create();
        $reporter->companies()->attach($company->id);

        return (int) DB::table('diagnostic_reports')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $company->id,
            'reporter_user_id' => $reporter->id,
            'category' => $category,
            'module' => $module,
            'impact' => $impact,
            'error_kind' => 'load_failed',
            'browser_family' => 'edge',
            'viewport' => 'desktop',
            'status' => $status,
            'assigned_platform_admin_user_id' => $assignee,
            'created_at' => $created,
            'updated_at' => $created,
        ]);
    }

    public function test_empty_scope_returns_fixed_zero_case_mix_and_seven_zero_dates(): void
    {
        $this->actingAs($this->admin());
        $res = $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('workflow_performance.reported', 0)
            ->assertJsonPath('workflow_performance.case_mix.by_module.dashboard', 0)
            ->assertJsonPath('workflow_performance.case_mix.by_module.accounting', 0)
            ->assertJsonPath('workflow_performance.case_mix.by_category.other', 0)
            ->assertJsonPath('workflow_performance.case_mix.by_impact.blocked', 0)
            ->assertJsonPath('workflow_performance.daily_reported.0.date', '2026-10-08')
            ->assertJsonPath('workflow_performance.daily_reported.6.date', '2026-10-14');
        $series = $res->json('workflow_performance.daily_reported');
        $this->assertCount(7, $series);
        $this->assertSame(0, array_sum(array_column($series, 'count')));
    }

    public function test_case_mix_counts_distinct_allowed_dimensions_and_terminal_statuses(): void
    {
        $company = $this->company();
        $this->report($company, now()->subDays(3), 'crm', 'page_error', 'blocked');
        $this->report($company, now()->subDays(2), 'crm', 'slow_response', 'minor', 'resolved');
        $this->report($company, now()->subDays(1), 'accounting', 'page_error', 'blocked', 'closed');

        $this->actingAs($this->admin())->getJson(self::URL)->assertOk()
            ->assertJsonPath('workflow_performance.reported', 3)
            ->assertJsonPath('workflow_performance.case_mix.by_module.crm', 2)
            ->assertJsonPath('workflow_performance.case_mix.by_module.accounting', 1)
            ->assertJsonPath('workflow_performance.case_mix.by_category.page_error', 2)
            ->assertJsonPath('workflow_performance.case_mix.by_category.slow_response', 1)
            ->assertJsonPath('workflow_performance.case_mix.by_impact.blocked', 2)
            ->assertJsonPath('workflow_performance.case_mix.by_impact.minor', 1)
            ->assertJsonPath('workflow_performance.case_mix.by_impact.unknown', 0);
    }

    public function test_daily_series_uses_server_calendar_days_with_inclusive_first_midnight(): void
    {
        $company = $this->company();
        $this->report($company, Carbon::parse('2026-10-07 23:59:59'));
        $this->report($company, Carbon::parse('2026-10-08 00:00:00'));
        $this->report($company, Carbon::parse('2026-10-08 18:30:00'));
        $this->report($company, Carbon::parse('2026-10-14 12:00:00'));
        $this->actingAs($this->admin());

        $result = $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('workflow_performance.reported', 4)
            ->assertJsonPath('workflow_performance.daily_reported.0.date', '2026-10-08')
            ->assertJsonPath('workflow_performance.daily_reported.0.count', 2)
            ->assertJsonPath('workflow_performance.daily_reported.1.count', 0)
            ->assertJsonPath('workflow_performance.daily_reported.6.date', '2026-10-14')
            ->assertJsonPath('workflow_performance.daily_reported.6.count', 1);
        $this->assertSame(3, array_sum(array_column($result->json('workflow_performance.daily_reported'), 'count')));
    }

    public function test_rolling_window_boundary_excludes_old_and_future_rows_in_all_case_mix(): void
    {
        $company = $this->company();
        $this->report($company, now()->subDays(30), 'crm');
        $this->report($company, now()->subDays(30)->subSecond(), 'accounting');
        $this->report($company, now()->addSecond(), 'master_data');
        $this->actingAs($this->admin())->getJson(self::URL)->assertOk()
            ->assertJsonPath('workflow_performance.reported', 1)
            ->assertJsonPath('workflow_performance.case_mix.by_module.crm', 1)
            ->assertJsonPath('workflow_performance.case_mix.by_module.accounting', 0)
            ->assertJsonPath('workflow_performance.case_mix.by_module.master_data', 0)
            ->assertJsonPath('workflow_performance.daily_reported.6.count', 0)
            ->assertJsonPath('summary.total', 3);
    }

    public function test_company_category_impact_filters_restrict_trends_and_case_mix(): void
    {
        $a = $this->company('A');
        $b = $this->company('B');
        $this->report($a, now()->subDay(), 'crm', 'page_error', 'blocked');
        $this->report($a, now()->subDay(), 'accounting', 'other', 'blocked');
        $this->report($a, now()->subDay(), 'dashboard', 'page_error', 'minor');
        $this->report($b, now()->subDay(), 'master_data', 'page_error', 'blocked');

        $this->actingAs($this->admin())->getJson(self::URL.'?company_id='.$a->id.'&category=page_error&impact=blocked')
            ->assertOk()->assertJsonPath('workflow_performance.reported', 1)
            ->assertJsonPath('workflow_performance.case_mix.by_module.crm', 1)
            ->assertJsonPath('workflow_performance.case_mix.by_module.master_data', 0)
            ->assertJsonPath('workflow_performance.daily_reported.5.count', 1);
    }

    public function test_queue_only_status_assignment_age_and_pagination_do_not_change_case_mix(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $this->report($company, now()->subDays(8), 'crm', status: 'resolved', assignee: $actor->id);
        $this->report($company, now()->subDay(), 'accounting');
        $this->actingAs($actor)->getJson(self::URL.'?status=closed&assignment=unassigned&age=over_7d&inactivity=over_7d&sort=oldest&page=2&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 0)
            ->assertJsonPath('workflow_performance.reported', 2)
            ->assertJsonPath('workflow_performance.case_mix.by_module.crm', 1)
            ->assertJsonPath('workflow_performance.case_mix.by_module.accounting', 1)
            ->assertJsonPath('workflow_performance.daily_reported.5.count', 1);
    }

    public function test_legacy_unknown_dimension_is_never_returned_as_a_dynamic_key(): void
    {
        $company = $this->company();
        $this->report($company, now()->subDay(), 'raw_secret_module', 'raw_category', 'raw_impact');
        $this->actingAs($this->admin());
        $res = $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('workflow_performance.reported', 1)
            ->assertJsonPath('workflow_performance.case_mix.by_module.dashboard', 0)
            ->assertJsonPath('workflow_performance.daily_reported.5.count', 1);
        $caseMix = $res->json('workflow_performance.case_mix');
        $this->assertArrayNotHasKey('raw_secret_module', $caseMix['by_module']);
        $this->assertArrayNotHasKey('raw_category', $caseMix['by_category']);
        $this->assertArrayNotHasKey('raw_impact', $caseMix['by_impact']);
    }

    public function test_guest_tenant_unverified_admin_and_company_header_cannot_read_trends(): void
    {
        $company = $this->company();
        $this->report($company, now()->subDay());
        $this->getJson(self::URL)->assertUnauthorized();
        $tenant = User::factory()->create();
        $tenant->companies()->attach($company->id);
        $this->actingAs($tenant)->getJson(self::URL)->assertForbidden();
        $this->actingAs($this->admin(false))->getJson(self::URL)->assertStatus(428);
        $this->actingAs($this->admin())->withHeaders(['X-Company-ID' => (string) $company->id])
            ->getJson(self::URL)->assertStatus(422);
    }

    public function test_case_mix_is_read_only_and_write_lock_stays_enabled(): void
    {
        $company = $this->company();
        $id = $this->report($company, now()->subDay());
        $before = DB::table('diagnostic_reports')->where('id', $id)->first();
        $this->actingAs($this->admin());
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('workflow_performance.case_mix.by_module.dashboard', 1);
        $this->assertSame(0, DB::table('diagnostic_report_events')->count());
        $this->assertSame($before->workflow_version, DB::table('diagnostic_reports')->where('id', $id)->value('workflow_version'));
        $this->patchJson(self::URL.'/'.DB::table('diagnostic_reports')->where('id', $id)->value('public_id').'/status', [
            'status' => 'triaged', 'workflow_version' => 0,
        ])->assertStatus(423);
        $this->assertDatabaseHas('diagnostic_reports', ['id' => $id, 'status' => 'new']);
    }
}
