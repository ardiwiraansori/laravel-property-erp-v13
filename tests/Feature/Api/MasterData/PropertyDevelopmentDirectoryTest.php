<?php

namespace Tests\Feature\Api\MasterData;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Models\PropertyDevelopment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Checkpoint 7.15: read-only, opt-in, company-scoped directory. */
final class PropertyDevelopmentDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/master-data/property-developments';

    private function company(string $code = 'ALPHA'): Company
    {
        return Company::query()->create(['code' => $code, 'name' => 'Company '.$code]);
    }

    private function member(Company $company, bool $grantView = true): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company->id);

        if ($grantView) {
            $permission = Permission::findOrCreate(PermissionName::MasterDataPropertyDevelopmentsView->value, 'web');
            $previousTeam = getPermissionsTeamId();
            try {
                setPermissionsTeamId($company->id);
                $user->givePermissionTo($permission);
            } finally {
                setPermissionsTeamId($previousTeam);
            }
        }

        return $user;
    }

    private function development(Company $company, string $code, string $name, bool $active = true): void
    {
        PropertyDevelopment::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'name' => $name,
            'is_active' => $active,
        ]);
    }

    private function login(User $user, Company $company): void
    {
        $this->actingAs($user)->withHeader('X-Company-ID', (string) $company->id);
    }

    public function test_legacy_get_without_query_parameters_remains_unpaginated(): void
    {
        $company = $this->company();
        $this->development($company, 'C', 'Cedar');
        $this->development($company, 'A', 'Aspen');
        $this->login($this->member($company), $company);

        $this->getJson(self::URL)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'A')
            ->assertJsonMissingPath('meta');
    }

    public function test_opt_in_pagination_uses_stable_order_and_bounded_size(): void
    {
        $company = $this->company();
        foreach (['Cedar', 'Aspen', 'Birch'] as $name) {
            $this->development($company, strtoupper($name), $name);
        }
        $this->login($this->member($company), $company);

        $this->getJson(self::URL.'?page=1&per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Aspen')
            ->assertJsonPath('data.1.name', 'Birch')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2);

        $this->getJson(self::URL.'?page=2&per_page=2')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cedar')
            ->assertJsonPath('meta.current_page', 2);
        $this->getJson(self::URL.'?page=3&per_page=2')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_matches_code_or_name_without_case_or_edge_whitespace(): void
    {
        $company = $this->company();
        $this->development($company, 'GRIYA-01', 'Serene Gardens');
        $this->development($company, 'OTHER', 'Orchid Estate');
        $this->login($this->member($company), $company);

        $this->getJson(self::URL.'?page=1&search=griya')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'GRIYA-01');
        $this->getJson(self::URL.'?page=1&search='.urlencode('  serene  '))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Serene Gardens');
    }

    public function test_search_treats_sql_wildcards_as_literal_characters(): void
    {
        $company = $this->company();
        $this->development($company, 'PERCENT', 'Palm % Avenue');
        $this->development($company, 'UNDER', 'Palm _ Avenue');
        $this->development($company, 'PLAIN', 'Palm A Avenue');
        $this->login($this->member($company), $company);

        $this->getJson(self::URL.'?search=%25&page=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'PERCENT');
        $this->getJson(self::URL.'?search=_&page=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'UNDER');
    }

    public function test_status_filter_can_select_active_inactive_or_all(): void
    {
        $company = $this->company();
        $this->development($company, 'A', 'Active Development');
        $this->development($company, 'I', 'Inactive Development', false);
        $this->login($this->member($company), $company);

        $this->getJson(self::URL.'?status=active')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'A');
        $this->getJson(self::URL.'?status=inactive')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'I');
        $this->getJson(self::URL.'?status=all')->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_search_status_and_pagination_combine_inside_current_company(): void
    {
        $company = $this->company();
        $other = $this->company('BETA');
        $this->development($company, 'A', 'Griya One');
        $this->development($company, 'B', 'Griya Two', false);
        $this->development($other, 'S', 'Griya Secret');
        $this->login($this->member($company), $company);

        $this->getJson(self::URL.'?page=1&per_page=1&status=active&search=Griya')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'A')
            ->assertJsonPath('meta.total', 1)->assertDontSee('Secret');
        $this->getJson(self::URL.'?page=1&status=all&search=Griya')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertDontSee('Secret');
    }

    public function test_same_user_in_two_companies_cannot_mix_directory_results(): void
    {
        $first = $this->company();
        $second = $this->company('BETA');
        $user = $this->member($first);
        $user->companies()->attach($second->id);
        $permission = Permission::findOrCreate(PermissionName::MasterDataPropertyDevelopmentsView->value, 'web');
        $previousTeam = getPermissionsTeamId();
        try {
            setPermissionsTeamId($second->id);
            $user->givePermissionTo($permission);
        } finally {
            setPermissionsTeamId($previousTeam);
        }
        $this->development($first, 'FIRST', 'Shared Name');
        $this->development($second, 'SECOND', 'Shared Name');

        $this->login($user, $first);
        $this->getJson(self::URL.'?page=1')->assertOk()->assertJsonPath('data.0.code', 'FIRST')
            ->assertJsonPath('meta.total', 1);
        $this->withHeader('X-Company-ID', (string) $second->id);
        $this->getJson(self::URL.'?page=1')->assertOk()->assertJsonPath('data.0.code', 'SECOND')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_invalid_filter_or_pagination_input_is_rejected(): void
    {
        $company = $this->company();
        $this->login($this->member($company), $company);

        foreach (['page=0', 'page=nope', 'per_page=0', 'per_page=51', 'per_page=nope',
            'status=deleted', 'search[]=unsafe', 'search='.str_repeat('a', 101)] as $params) {
            $this->getJson(self::URL.'?'.$params)->assertUnprocessable();
        }
    }

    public function test_view_permission_still_required_for_directory_queries(): void
    {
        $company = $this->company();
        $this->login($this->member($company, false), $company);
        $this->getJson(self::URL.'?page=1&search=Griya')->assertForbidden();
    }

    public function test_guest_nonmember_and_missing_header_cannot_list(): void
    {
        $company = $this->company();
        $this->getJson(self::URL.'?page=1')->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->withHeader('X-Company-ID', (string) $company->id)
            ->getJson(self::URL.'?page=1')->assertForbidden();
        $this->withHeaders(['X-Company-ID' => ''])
            ->getJson(self::URL.'?page=1')->assertUnprocessable();
    }

    public function test_inactive_company_is_denied_and_no_record_is_changed_by_get(): void
    {
        $company = $this->company();
        $this->development($company, 'KEEP', 'Keep Unchanged');
        $user = $this->member($company);
        $this->login($user, $company);
        $before = PropertyDevelopment::query()->firstOrFail()->toArray();
        $this->getJson(self::URL.'?page=1&status=all')->assertOk();
        $this->assertSame($before, PropertyDevelopment::query()->firstOrFail()->toArray());

        $company->update(['is_active' => false]);
        $this->getJson(self::URL.'?page=1')->assertForbidden();
    }
}
