<?php

namespace Tests\Feature\Api\MasterData;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Models\PropertyDevelopment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Checkpoint 7.19: company-scoped, read-only summary using the existing view permission. */
final class PropertyDevelopmentSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/master-data/property-developments/summary';

    private function company(string $code = 'ALPHA'): Company
    {
        return Company::query()->create(['code' => $code, 'name' => 'Company '.$code]);
    }

    private function member(Company $company, bool $grantView = true): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company->id);

        if ($grantView) {
            $this->grant($user, $company);
        }

        return $user;
    }

    private function grant(User $user, Company $company): void
    {
        $permission = Permission::findOrCreate(PermissionName::MasterDataPropertyDevelopmentsView->value, 'web');
        $previousTeam = getPermissionsTeamId();
        try {
            setPermissionsTeamId($company->id);
            $user->givePermissionTo($permission);
        } finally {
            setPermissionsTeamId($previousTeam);
        }
    }

    private function development(Company $company, string $code, bool $active = true): PropertyDevelopment
    {
        return PropertyDevelopment::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'name' => $code.' Residence',
            'is_active' => $active,
        ]);
    }

    private function login(User $user, Company $company): void
    {
        $this->actingAs($user)->withHeader('X-Company-ID', (string) $company->id);
    }

    public function test_empty_tenant_returns_three_zero_counts(): void
    {
        $company = $this->company();
        $this->login($this->member($company), $company);

        $this->getJson(self::URL)->assertOk()->assertExactJson([
            'data' => ['total' => 0, 'active' => 0, 'inactive' => 0],
        ]);
    }

    public function test_counts_active_and_inactive_developments_in_one_company(): void
    {
        $company = $this->company();
        $this->development($company, 'A');
        $this->development($company, 'B');
        $this->development($company, 'C', false);
        $this->login($this->member($company), $company);

        $this->getJson(self::URL)->assertOk()->assertExactJson([
            'data' => ['total' => 3, 'active' => 2, 'inactive' => 1],
        ]);
    }

    public function test_foreign_company_rows_never_affect_summary(): void
    {
        $company = $this->company();
        $foreign = $this->company('BETA');
        $this->development($company, 'A', false);
        $this->development($foreign, 'PRIVATE-1');
        $this->development($foreign, 'PRIVATE-2');
        $this->login($this->member($company), $company);

        $this->getJson(self::URL)->assertOk()->assertExactJson([
            'data' => ['total' => 1, 'active' => 0, 'inactive' => 1],
        ]);
    }

    public function test_same_user_sees_only_selected_company_even_with_two_memberships(): void
    {
        $first = $this->company();
        $second = $this->company('BETA');
        $user = $this->member($first);
        $user->companies()->attach($second->id);
        $this->grant($user, $second);
        $this->development($first, 'ONE');
        $this->development($second, 'TWO', false);
        $this->development($second, 'THREE', false);

        $this->login($user, $first);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.active', 1);

        $this->withHeader('X-Company-ID', (string) $second->id);
        $this->getJson(self::URL)->assertOk()->assertExactJson([
            'data' => ['total' => 2, 'active' => 0, 'inactive' => 2],
        ]);
    }

    public function test_query_string_cannot_override_active_tenant(): void
    {
        $first = $this->company();
        $second = $this->company('BETA');
        $this->development($first, 'OWN');
        $this->development($second, 'PRIVATE');
        $this->login($this->member($first), $first);

        $this->getJson(self::URL.'?company_id='.$second->id.'&status=inactive&page=999')
            ->assertOk()->assertExactJson([
                'data' => ['total' => 1, 'active' => 1, 'inactive' => 0],
            ]);
    }

    public function test_view_permission_is_mandatory_and_dashboard_permission_alone_is_not_enough(): void
    {
        $company = $this->company();
        $user = $this->member($company, false);
        $this->login($user, $company);
        $this->getJson(self::URL)->assertForbidden();

        $permission = Permission::findOrCreate(PermissionName::DashboardView->value, 'web');
        $previousTeam = getPermissionsTeamId();
        try {
            setPermissionsTeamId($company->id);
            $user->givePermissionTo($permission);
        } finally {
            setPermissionsTeamId($previousTeam);
        }
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_guest_missing_header_and_nonmember_cannot_read_summary(): void
    {
        $company = $this->company();
        $this->getJson(self::URL)->assertUnauthorized();

        $user = $this->member($company);
        $this->actingAs($user)->getJson(self::URL)->assertUnprocessable();

        $other = $this->company('BETA');
        $this->withHeader('X-Company-ID', (string) $other->id)
            ->getJson(self::URL)->assertForbidden();
    }

    public function test_inactive_company_is_forbidden(): void
    {
        $company = $this->company();
        $this->development($company, 'A');
        $this->login($this->member($company), $company);
        $company->update(['is_active' => false]);

        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_summary_is_read_only_and_reflects_changes_to_existing_rows(): void
    {
        $company = $this->company();
        $model = $this->development($company, 'A');
        $this->login($this->member($company), $company);
        $before = $model->fresh()->toArray();

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.active', 1);
        $this->assertSame($before, $model->fresh()->toArray());

        $model->update(['is_active' => false]);
        $this->getJson(self::URL)->assertOk()->assertExactJson([
            'data' => ['total' => 1, 'active' => 0, 'inactive' => 1],
        ]);
    }
}
