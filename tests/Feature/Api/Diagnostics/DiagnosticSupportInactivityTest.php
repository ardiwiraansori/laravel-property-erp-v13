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

/** Checkpoint 7.11: read-only report inactivity; unchanged tenant/MFA boundaries. */
final class DiagnosticSupportInactivityTest extends TestCase
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

    private function report(Company $company, string $status, int $ageHours, ?int $inactiveHours = null,
        string $impact = 'partial', ?int $assignee = null): string
    {
        $reporter = User::factory()->create();
        $reporter->companies()->attach($company->id);
        $id = (string) Str::ulid();
        DB::table('diagnostic_reports')->insert([
            'public_id' => $id,
            'company_id' => $company->id,
            'reporter_user_id' => $reporter->id,
            'category' => 'page_error',
            'module' => 'dashboard',
            'impact' => $impact,
            'error_kind' => 'load_failed',
            'browser_family' => 'edge',
            'viewport' => 'desktop',
            'status' => $status,
            'assigned_platform_admin_user_id' => $assignee,
            'created_at' => now()->subHours($ageHours),
            'updated_at' => $inactiveHours === null ? null : now()->subHours($inactiveHours),
        ]);
        return $id;
    }

    public function test_inactivity_buckets_are_separate_from_report_age_and_use_exact_boundaries(): void
    {
        $company = $this->company();
        $this->report($company, 'new', 200, 23);
        $this->report($company, 'triaged', 200, 24);
        $this->report($company, 'in_progress', 200, 72);
        $this->report($company, 'new', 200, 168);
        $this->report($company, 'new', 240, 190);
        $this->report($company, 'resolved', 300, 250);
        $this->report($company, 'closed', 300, 280);
        $this->actingAs($this->admin());
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('summary.total', 7)
            ->assertJsonPath('open_aging.total', 5)
            ->assertJsonPath('open_aging.over_7d', 5)
            ->assertJsonPath('open_inactivity.total', 5)
            ->assertJsonPath('open_inactivity.under_24h', 1)
            ->assertJsonPath('open_inactivity.day_1_3', 1)
            ->assertJsonPath('open_inactivity.day_3_7', 1)
            ->assertJsonPath('open_inactivity.over_7d', 2);
    }

    public function test_inactivity_lanes_only_include_open_reports_and_filter_rows(): void
    {
        $company = $this->company();
        $recent = $this->report($company, 'new', 200, 1);
        $oneDay = $this->report($company, 'triaged', 200, 24);
        $threeDays = $this->report($company, 'in_progress', 200, 72);
        $week = $this->report($company, 'new', 200, 168);
        $closed = $this->report($company, 'closed', 200, 168);
        $this->actingAs($this->admin());
        foreach (['under_24h' => $recent, 'day_1_3' => $oneDay,
            'day_3_7' => $threeDays, 'over_7d' => $week] as $bucket => $id) {
            $this->getJson(self::URL.'?inactivity='.$bucket)->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.public_id', $id)
                ->assertJsonPath('open_inactivity.total', 4)
                ->assertJsonPath('summary.total', 5)
                ->assertDontSee($closed);
        }
    }

    public function test_updated_at_falls_back_to_created_at_for_legacy_null_rows(): void
    {
        $company = $this->company();
        $legacy = $this->report($company, 'new', 168, null);
        $this->actingAs($this->admin());
        $this->getJson(self::URL.'?inactivity=over_7d')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('open_inactivity.over_7d', 1)
            ->assertJsonPath('data.0.public_id', $legacy)
            ->assertJsonPath('data.0.updated_at', now()->subHours(168)->toIso8601String());
    }

    public function test_age_status_assignment_and_inactivity_filters_intersect_without_altering_summaries(): void
    {
        $company = $this->company();
        $actor = $this->admin();
        $matches = $this->report($company, 'triaged', 200, 190, 'blocked', $actor->id);
        $this->report($company, 'new', 200, 190, 'blocked');
        $this->report($company, 'triaged', 200, 1, 'blocked', $actor->id);
        $this->actingAs($actor);
        $this->getJson(self::URL.'?impact=blocked&age=over_7d&inactivity=over_7d&status=triaged&assignment=mine')
            ->assertOk()->assertJsonPath('data.0.public_id', $matches)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.mine', 2)
            ->assertJsonPath('open_aging.over_7d', 3)
            ->assertJsonPath('open_inactivity.over_7d', 2);
        $this->getJson(self::URL.'?status=closed&inactivity=over_7d')->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonPath('open_inactivity.total', 3);
    }

    public function test_company_and_impact_scope_inactivity_but_page_and_assignment_do_not(): void
    {
        $a = $this->company('A');
        $b = $this->company('B');
        $actor = $this->admin();
        $this->report($a, 'new', 200, 200, 'blocked', $actor->id);
        $this->report($a, 'new', 200, 1, 'partial');
        $other = $this->report($b, 'new', 200, 200, 'blocked');
        $this->actingAs($actor);
        $this->getJson(self::URL.'?company_id='.$a->id.'&impact=blocked&assignment=unassigned')
            ->assertOk()->assertJsonPath('meta.total', 0)
            ->assertJsonPath('open_inactivity.total', 1)
            ->assertJsonPath('open_inactivity.over_7d', 1)
            ->assertDontSee($other);
    }

    public function test_workflow_change_resets_inactivity_clock_without_resetting_original_age(): void
    {
        config()->set('platform.mutations_enabled', true);
        $company = $this->company();
        $publicId = $this->report($company, 'new', 200, 200);
        $this->actingAs($this->admin());
        $this->getJson(self::URL.'?inactivity=over_7d')->assertJsonPath('meta.total', 1);
        $this->patchJson('/api/platform/diagnostics/reports/'.$publicId.'/status', [
            'status' => 'triaged', 'workflow_version' => 0,
        ])->assertOk()->assertJsonPath('data.workflow_version', 1);
        $this->getJson(self::URL.'?inactivity=under_24h')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.public_id', $publicId)
            ->assertJsonPath('data.0.workflow_version', 1)
            ->assertJsonPath('open_aging.over_7d', 1)
            ->assertJsonPath('open_inactivity.under_24h', 1)
            ->assertJsonPath('open_inactivity.over_7d', 0);
        $this->assertDatabaseCount('diagnostic_report_events', 1);
    }

    public function test_workflow_assignment_also_refreshes_inactivity_without_adding_tenant_access(): void
    {
        config()->set('platform.mutations_enabled', true);
        $company = $this->company();
        $publicId = $this->report($company, 'new', 200, 200);
        $actor = $this->admin();
        $assignee = $this->admin(false);
        $this->actingAs($actor);
        $this->patchJson('/api/platform/diagnostics/reports/'.$publicId.'/assignment', [
            'assignee_user_id' => $assignee->id, 'workflow_version' => 0,
        ])->assertOk()->assertJsonPath('data.assigned_platform_admin_user_id', $assignee->id)
            ->assertJsonPath('data.workflow_version', 1);
        $this->getJson(self::URL.'?inactivity=under_24h&assignment=assigned')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.public_id', $publicId)
            ->assertJsonPath('open_aging.over_7d', 1)
            ->assertJsonPath('open_inactivity.under_24h', 1);
        $this->assertDatabaseCount('diagnostic_report_events', 1);
        $this->assertDatabaseCount('company_user', 1); // Reporter only: no tenant membership for platform admins.
        $this->assertDatabaseMissing('company_user', ['user_id' => $assignee->id]);
    }

    public function test_stale_first_and_recent_activity_sort_are_stable_across_ties(): void
    {
        $company = $this->company();
        $oldest = $this->report($company, 'new', 200, 190);
        $tie1 = $this->report($company, 'new', 200, 30);
        $tie2 = $this->report($company, 'triaged', 200, 30);
        $newest = $this->report($company, 'new', 200, 1);
        $this->actingAs($this->admin());
        $this->assertSame([$oldest, $tie1, $tie2, $newest],
            array_column($this->getJson(self::URL.'?sort=stale_first')->assertOk()->json('data'), 'public_id'));
        $this->assertSame([$newest, $tie2, $tie1, $oldest],
            array_column($this->getJson(self::URL.'?sort=recent_activity')->assertOk()->json('data'), 'public_id'));
    }

    public function test_inactivity_pagination_keeps_global_counts_and_no_repeated_rows(): void
    {
        $company = $this->company();
        $this->report($company, 'new', 200, 200);
        $this->report($company, 'new', 200, 190);
        $this->report($company, 'new', 200, 180);
        $this->actingAs($this->admin());
        $first = $this->getJson(self::URL.'?sort=stale_first&per_page=1&page=1')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('open_inactivity.over_7d', 3);
        $second = $this->getJson(self::URL.'?sort=stale_first&per_page=1&page=2')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('open_inactivity.over_7d', 3);
        $this->assertNotSame($first->json('data.0.public_id'), $second->json('data.0.public_id'));
        $this->assertGreaterThan($first->json('data.0.updated_at'), $second->json('data.0.updated_at'));
    }

    public function test_invalid_activity_and_sort_values_cannot_trigger_read_audit(): void
    {
        $this->actingAs($this->admin());
        foreach (['inactivity=week', 'inactivity=blocked', 'inactivity=over_7d%27%20OR%201%3D1',
            'sort=updated_at', 'sort=%3BDROP', 'sort=random'] as $filter) {
            $this->getJson(self::URL.'?'.$filter)->assertUnprocessable();
        }
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_guest_tenant_unverified_admin_and_tenant_header_are_denied(): void
    {
        $company = $this->company();
        $publicId = $this->report($company, 'new', 200, 200);
        $this->getJson(self::URL.'?inactivity=over_7d')->assertUnauthorized();
        $tenant = User::factory()->create();
        $tenant->companies()->attach($company->id);
        $this->actingAs($tenant)->getJson(self::URL.'?inactivity=over_7d')->assertForbidden();
        $this->actingAs($this->admin(false))->getJson(self::URL)->assertStatus(428);
        $this->actingAs($this->admin())->withHeader('X-Company-ID', (string) $company->id)
            ->getJson(self::URL)->assertStatus(422);
        $this->assertDatabaseHas('diagnostic_reports', ['public_id' => $publicId, 'status' => 'new']);
        $this->assertDatabaseCount('diagnostic_report_events', 0);
    }

    public function test_empty_open_scope_returns_zero_counts_and_never_exposes_private_data(): void
    {
        $company = $this->company();
        $this->report($company, 'resolved', 200, 200);
        $this->report($company, 'closed', 200, 200);
        $this->actingAs($this->admin());
        $response = $this->getJson(self::URL.'?inactivity=over_7d')->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('open_inactivity.total', 0)
            ->assertJsonPath('open_inactivity.under_24h', 0)
            ->assertJsonPath('open_inactivity.day_1_3', 0)
            ->assertJsonPath('open_inactivity.day_3_7', 0)
            ->assertJsonPath('open_inactivity.over_7d', 0);
        $response->assertDontSee('cookie')->assertDontSee('email')->assertDontSee('screenshot');
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform.diagnostics.read']);
    }
}
