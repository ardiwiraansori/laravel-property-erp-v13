<?php

namespace Tests\Feature\Api\Diagnostics;

use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Checkpoint 7.14: tenant reporter status-only history, without platform internals. */
final class DiagnosticTenantProgressTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/diagnostics/reports/';

    private function company(string $code, bool $active = true): Company
    {
        return Company::query()->create(['code' => $code, 'name' => 'Test '.$code, 'is_active' => $active]);
    }

    private function member(Company $company): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company->id);

        return $user;
    }

    private function report(Company $company, User $reporter, string $status = 'new'): string
    {
        $id = (string) Str::ulid();
        DB::table('diagnostic_reports')->insert([
            'public_id' => $id,
            'company_id' => $company->id,
            'reporter_user_id' => $reporter->id,
            'category' => 'page_error',
            'module' => 'dashboard',
            'impact' => 'partial',
            'error_kind' => 'load_failed',
            'browser_family' => 'edge',
            'viewport' => 'desktop',
            'status' => $status,
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function event(string $publicId, Company $company, User $actor, string $action,
        ?string $from, ?string $to, int $minute = 0, ?int $otherAssignee = null): void
    {
        $reportId = DB::table('diagnostic_reports')->where('public_id', $publicId)->value('id');
        DB::table('diagnostic_report_events')->insert([
            'diagnostic_report_id' => $reportId,
            'company_id' => $company->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'to_assignee_user_id' => $otherAssignee,
            'created_at' => now()->subMinutes($minute),
        ]);
    }

    private function login(User $user, Company $company): void
    {
        $this->actingAs($user)->withHeader('X-Company-ID', (string) $company->id);
    }

    public function test_guest_cannot_open_report_progress(): void
    {
        $company = $this->company('A');
        $report = $this->report($company, $this->member($company));
        $this->getJson(self::URL.$report)->assertUnauthorized();
    }

    public function test_company_header_and_membership_are_required(): void
    {
        $company = $this->company('A');
        $report = $this->report($company, $this->member($company));
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->getJson(self::URL.$report)->assertStatus(422);
        $this->withHeader('X-Company-ID', (string) $company->id)
            ->getJson(self::URL.$report)->assertForbidden();
    }

    public function test_reporter_can_read_only_approved_status_history(): void
    {
        $company = $this->company('A');
        $reporter = $this->member($company);
        $actor = User::factory()->create();
        $report = $this->report($company, $reporter, 'resolved');
        $this->event($report, $company, $actor, 'status_changed', 'new', 'triaged', 10);
        $this->event($report, $company, $actor, 'assignment_changed', 'triaged', 'triaged', 8, $actor->id);
        $this->event($report, $company, $actor, 'status_changed', 'in_progress', 'resolved', 2);
        $this->login($reporter, $company);
        $result = $this->getJson(self::URL.$report)->assertOk()
            ->assertJsonPath('data.public_id', $report)
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('history.0.to_status', 'resolved')
            ->assertJsonPath('history.1.to_status', 'triaged');

        $this->assertSame(['public_id', 'category', 'module', 'impact', 'status', 'created_at', 'updated_at'],
            array_keys($result->json('data')));
        $this->assertSame(['from_status', 'to_status', 'created_at'], array_keys($result->json('history.0')));
        $this->assertArrayNotHasKey('actor_user_id', $result->json('history.0'));
        $this->assertArrayNotHasKey('assigned_platform_admin_user_id', $result->json('data'));
        $this->assertArrayNotHasKey('company_id', $result->json('data'));
    }

    public function test_other_reporter_in_same_company_gets_404_not_history(): void
    {
        $company = $this->company('A');
        $owner = $this->member($company);
        $other = $this->member($company);
        $report = $this->report($company, $owner);
        $this->event($report, $company, User::factory()->create(), 'status_changed', 'new', 'triaged');
        $this->login($other, $company);
        $this->getJson(self::URL.$report)->assertNotFound();
    }

    public function test_same_user_in_two_companies_cannot_cross_read(): void
    {
        $first = $this->company('A');
        $second = $this->company('B');
        $user = $this->member($first);
        $user->companies()->attach($second->id);
        $a = $this->report($first, $user);
        $b = $this->report($second, $user, 'triaged');
        $this->login($user, $first);
        $this->getJson(self::URL.$a)->assertOk();
        $this->getJson(self::URL.$b)->assertNotFound();
        $this->withHeader('X-Company-ID', (string) $second->id);
        $this->getJson(self::URL.$b)->assertOk();
        $this->getJson(self::URL.$a)->assertNotFound();
    }

    public function test_platform_identity_without_tenant_membership_cannot_use_tenant_endpoint(): void
    {
        $company = $this->company('A');
        $report = $this->report($company, $this->member($company));
        $platformUser = User::factory()->create();
        PlatformAdmin::query()->create(['user_id' => $platformUser->id, 'is_active' => true, 'granted_at' => now()]);
        $this->login($platformUser, $company);
        $this->getJson(self::URL.$report)->assertForbidden();
    }

    public function test_empty_status_history_is_explicit_and_does_not_infer_events(): void
    {
        $company = $this->company('A');
        $user = $this->member($company);
        $report = $this->report($company, $user, 'closed');
        $this->login($user, $company);
        $this->getJson(self::URL.$report)->assertOk()->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data.status', 'closed')->assertJsonCount(0, 'history');
    }

    public function test_status_history_pagination_is_bounded_and_deterministic(): void
    {
        $company = $this->company('A');
        $user = $this->member($company);
        $actor = User::factory()->create();
        $report = $this->report($company, $user);
        for ($i = 0; $i < 23; $i++) {
            $this->event($report, $company, $actor, 'status_changed', 'triaged', 'in_progress', $i);
        }
        $this->login($user, $company);
        $first = $this->getJson(self::URL.$report.'?page=1')->assertOk()
            ->assertJsonPath('meta.total', 23)->assertJsonPath('meta.last_page', 2)->assertJsonCount(20, 'history');
        $second = $this->getJson(self::URL.$report.'?page=2')->assertOk()
            ->assertJsonPath('meta.total', 23)->assertJsonCount(3, 'history');
        $this->assertNotSame($first->json('history.0.created_at'), $second->json('history.0.created_at'));
    }

    public function test_invalid_page_or_public_id_is_rejected_without_data_disclosure(): void
    {
        $company = $this->company('A');
        $user = $this->member($company);
        $report = $this->report($company, $user);
        $this->login($user, $company);
        $this->getJson(self::URL.$report.'?page=0')->assertUnprocessable();
        $this->getJson(self::URL.$report.'?page=abc')->assertUnprocessable();
        $this->getJson(self::URL.'invalid-format')->assertNotFound();
        $this->getJson(self::URL.Str::ulid())->assertNotFound();
    }

    public function test_inactive_company_cannot_read_report_progress(): void
    {
        $company = $this->company('A', false);
        $user = $this->member($company);
        $report = $this->report($company, $user);
        $this->login($user, $company);
        $this->getJson(self::URL.$report)->assertForbidden();
    }

    public function test_history_rejects_unknown_statuses_and_does_not_change_data_or_unlock_writes(): void
    {
        $company = $this->company('A');
        $user = $this->member($company);
        $actor = User::factory()->create();
        $report = $this->report($company, $user);
        $this->event($report, $company, $actor, 'status_changed', 'new', 'SECRET_INTERNAL');
        $this->event($report, $company, $actor, 'status_changed', 'new', 'triaged');
        $before = DB::table('diagnostic_reports')->where('public_id', $report)->first();
        $this->login($user, $company);
        $result = $this->getJson(self::URL.$report)->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame('triaged', $result->json('history.0.to_status'));
        $this->assertSame($before->workflow_version,
            DB::table('diagnostic_reports')->where('public_id', $report)->value('workflow_version'));
        $this->assertDatabaseCount('diagnostic_report_events', 2);
        $this->assertFalse(config('platform.mutations_enabled'));
    }
}
