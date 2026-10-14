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

/** Checkpoint 7.10: aging boundaries and ordering, read-only + MFA safety. */
final class DiagnosticSupportAgingTest extends TestCase
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
        PlatformAdmin::query()->create([
            'user_id' => $user->id, 'is_active' => true, 'granted_at' => now(),
        ]);
        if ($verified) {
            $this->withVerifiedPlatformMfa($user);
        }
        return $user;
    }

    private function report(Company $company, string $status = 'new', int $hoursAgo = 1,
        string $impact = 'partial', ?int $assignee = null): string
    {
        $reporter = User::factory()->create();
        $reporter->companies()->attach($company->id);
        $id = (string) Str::ulid();
        $created = now()->subHours($hoursAgo);
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
            'created_at' => $created,
            'updated_at' => $created,
        ]);
        return $id;
    }

    public function test_aging_boundaries_include_exact_24_72_and_168_hour_cutoffs_in_older_bucket(): void
    {
        $company = $this->company();
        $this->report($company, 'new', 23);
        $this->report($company, 'triaged', 24);
        $this->report($company, 'in_progress', 72);
        $this->report($company, 'new', 168);
        $this->report($company, 'new', 240);
        $this->report($company, 'resolved', 300);
        $this->report($company, 'closed', 400);
        $this->actingAs($this->admin());

        $response = $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('summary.total', 7)
            ->assertJsonPath('open_aging.total', 5)
            ->assertJsonPath('open_aging.under_24h', 1)
            ->assertJsonPath('open_aging.day_1_3', 1)
            ->assertJsonPath('open_aging.day_3_7', 1)
            ->assertJsonPath('open_aging.over_7d', 2);
        $this->assertSame(['total', 'under_24h', 'day_1_3', 'day_3_7', 'over_7d'],
            array_keys($response->json('open_aging')));
        $response->assertDontSee('email')->assertDontSee('cookie')->assertDontSee('screenshot');
    }

    public function test_each_age_lane_contains_only_its_own_open_reports_and_ignores_closed(): void
    {
        $company = $this->company();
        $recent = $this->report($company, 'new', 0);
        $oneDay = $this->report($company, 'triaged', 24);
        $threeDays = $this->report($company, 'in_progress', 72);
        $week = $this->report($company, 'new', 168);
        $closed = $this->report($company, 'closed', 168);
        $this->actingAs($this->admin());
        foreach ([
            'under_24h' => $recent,
            'day_1_3' => $oneDay,
            'day_3_7' => $threeDays,
            'over_7d' => $week,
        ] as $bucket => $id) {
            $this->getJson(self::URL.'?age='.$bucket)->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.public_id', $id)
                ->assertJsonPath('summary.total', 5)
                ->assertJsonPath('open_aging.total', 4)
                ->assertDontSee($closed);
        }
        $this->assertDatabaseCount('diagnostic_report_events', 0);
    }

    public function test_age_filter_and_status_are_intersected_not_overridden(): void
    {
        $company = $this->company();
        $this->report($company, 'new', 192);
        $triaged = $this->report($company, 'triaged', 192);
        $this->actingAs($this->admin());
        $this->getJson(self::URL.'?age=over_7d&status=triaged')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.public_id', $triaged)
            ->assertJsonPath('open_aging.total', 2);
        $this->getJson(self::URL.'?age=over_7d&status=closed')->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonPath('open_aging.total', 2);
    }

    public function test_aging_respects_company_and_impact_but_not_assignment_lanes(): void
    {
        $a = $this->company('A');
        $b = $this->company('B');
        $actor = $this->admin();
        $this->report($a, 'new', 200, 'blocked', $actor->id);
        $this->report($a, 'triaged', 5, 'minor');
        $other = $this->report($b, 'new', 200, 'blocked');
        $this->actingAs($actor);
        $this->getJson(self::URL.'?company_id='.$a->id.'&impact=blocked&assignment=unassigned')
            ->assertOk()->assertJsonPath('meta.total', 0)
            ->assertJsonPath('open_aging.total', 1)
            ->assertJsonPath('open_aging.over_7d', 1)
            ->assertJsonPath('summary.mine', 1)->assertDontSee($other);
    }

    public function test_oldest_and_newest_sorting_follow_created_at_with_stable_tie_breaker(): void
    {
        $company = $this->company();
        $oldest = $this->report($company, 'new', 200);
        $tie1 = $this->report($company, 'new', 30);
        $tie2 = $this->report($company, 'triaged', 30);
        $newest = $this->report($company, 'new', 1);
        $this->actingAs($this->admin());
        $this->assertSame([$oldest, $tie1, $tie2, $newest],
            array_column($this->getJson(self::URL.'?sort=oldest')->assertOk()->json('data'), 'public_id'));
        $this->assertSame([$newest, $tie2, $tie1, $oldest],
            array_column($this->getJson(self::URL.'?sort=newest')->assertOk()->json('data'), 'public_id'));
    }

    public function test_pagination_preserves_aging_summary_and_order_between_pages(): void
    {
        $company = $this->company();
        $this->report($company, 'new', 168);
        $this->report($company, 'new', 169);
        $this->report($company, 'new', 170);
        $this->actingAs($this->admin());
        $page1 = $this->getJson(self::URL.'?age=over_7d&sort=oldest&per_page=1&page=1')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('open_aging.over_7d', 3);
        $page2 = $this->getJson(self::URL.'?age=over_7d&sort=oldest&per_page=1&page=2')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('open_aging.over_7d', 3);
        $this->assertNotSame($page1->json('data.0.public_id'), $page2->json('data.0.public_id'));
        $this->assertGreaterThan($page1->json('data.0.created_at'), $page2->json('data.0.created_at'));
    }

    public function test_invalid_age_and_sort_values_are_rejected_before_any_read_audit(): void
    {
        $this->actingAs($this->admin());
        foreach (['age=older', 'age=all', 'age=over_7d%27%20OR%201%3D1', 'sort=random', 'sort=DROP'] as $filter) {
            $this->getJson(self::URL.'?'.$filter)->assertUnprocessable();
        }
        $this->assertDatabaseCount('platform_audit_logs', 0);
    }

    public function test_aging_remains_platform_mfa_only_and_read_only_when_platform_writes_locked(): void
    {
        $company = $this->company();
        $publicId = $this->report($company, 'new', 200);
        $this->getJson(self::URL.'?age=over_7d')->assertUnauthorized();
        $tenant = User::factory()->create();
        $tenant->companies()->attach($company->id);
        $this->actingAs($tenant)->getJson(self::URL.'?age=over_7d')->assertForbidden();
        $this->actingAs($this->admin(false))->getJson(self::URL.'?age=over_7d')->assertStatus(428);
        $this->actingAs($this->admin())->getJson(self::URL.'?age=over_7d')->assertOk()
            ->assertJsonPath('data.0.public_id', $publicId);
        $this->assertDatabaseHas('diagnostic_reports', ['public_id' => $publicId, 'status' => 'new']);
        $this->assertDatabaseCount('diagnostic_report_events', 0);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform.diagnostics.read']);
    }

    public function test_zero_open_reports_returns_zero_aging_even_with_closed_history(): void
    {
        $company = $this->company();
        $this->report($company, 'closed', 999);
        $this->actingAs($this->admin());
        $this->getJson(self::URL.'?age=over_7d')->assertOk()
            ->assertJsonPath('open_aging.total', 0)
            ->assertJsonPath('open_aging.under_24h', 0)
            ->assertJsonPath('open_aging.day_1_3', 0)
            ->assertJsonPath('open_aging.day_3_7', 0)
            ->assertJsonPath('open_aging.over_7d', 0)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('meta.total', 0);
    }
}
