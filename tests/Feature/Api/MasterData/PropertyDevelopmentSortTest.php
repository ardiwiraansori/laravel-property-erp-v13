<?php

namespace Tests\Feature\Api\MasterData;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Models\PropertyDevelopment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Stable allowlisted ordering for tenant directory and safe CSV export. */
final class PropertyDevelopmentSortTest extends TestCase
{
    use RefreshDatabase;

    private const DIRECTORY = '/api/master-data/property-developments';
    private const EXPORT = '/api/master-data/property-developments/export';

    private function company(string $code = 'ALPHA'): Company
    {
        return Company::query()->create(['code' => $code, 'name' => 'Company '.$code]);
    }

    private function record(Company $company, string $code, string $name, bool $active = true): PropertyDevelopment
    {
        return PropertyDevelopment::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'name' => $name,
            'is_active' => $active,
        ]);
    }

    private function member(Company $company, bool $grant = true): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company->id);
        if ($grant) {
            $this->grantView($user, $company);
        }
        return $user;
    }

    private function grantView(User $user, Company $company): void
    {
        $previous = getPermissionsTeamId();
        try {
            setPermissionsTeamId($company->id);
            $user->givePermissionTo(Permission::findOrCreate(PermissionName::MasterDataPropertyDevelopmentsView->value, 'web'));
        } finally {
            setPermissionsTeamId($previous);
        }
    }

    private function login(User $user, Company $company): void
    {
        $this->actingAs($user)->withHeader('X-Company-ID', (string) $company->id);
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $body): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($body, 3));
        rewind($stream);
        $rows = [];
        while (($line = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $line;
        }
        fclose($stream);
        return $rows;
    }

    public function test_legacy_get_keeps_unpaginated_name_ascending_contract(): void
    {
        $company = $this->company();
        $this->record($company, 'Z', 'Zebra');
        $this->record($company, 'A', 'Aspen');
        $this->login($this->member($company), $company);

        $this->getJson(self::DIRECTORY)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('meta')->assertJsonPath('data.0.name', 'Aspen');
        $this->getJson(self::DIRECTORY.'?sort=name_desc')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.name', 'Zebra');
    }

    public function test_name_ascending_descending_and_equal_values_have_stable_page_order(): void
    {
        $company = $this->company();
        $this->record($company, 'A', 'Aspen');
        $this->record($company, 'C', 'Cedar');
        $this->record($company, 'B', 'Cedar');
        $this->login($this->member($company), $company);

        $this->getJson(self::DIRECTORY.'?sort=name_asc&page=1&per_page=1')->assertOk()
            ->assertJsonPath('data.0.code', 'A')->assertJsonPath('meta.total', 3);
        $this->getJson(self::DIRECTORY.'?sort=name_desc&page=1&per_page=1')->assertOk()
            ->assertJsonPath('data.0.code', 'C');
        $this->getJson(self::DIRECTORY.'?sort=name_desc&page=2&per_page=1')->assertOk()
            ->assertJsonPath('data.0.code', 'B');
    }

    public function test_code_ascending_and_descending_apply_across_pages(): void
    {
        $company = $this->company();
        $this->record($company, 'C', 'Alfa');
        $this->record($company, 'A', 'Zulu');
        $this->record($company, 'B', 'Beta');
        $this->login($this->member($company), $company);

        $this->getJson(self::DIRECTORY.'?sort=code_asc&page=1&per_page=2')->assertOk()
            ->assertJsonPath('data.0.code', 'A')->assertJsonPath('data.1.code', 'B');
        $this->getJson(self::DIRECTORY.'?sort=code_desc&page=2&per_page=2')->assertOk()
            ->assertJsonPath('data.0.code', 'A');
    }

    public function test_created_date_ascending_and_descending_and_id_tiebreaker(): void
    {
        $company = $this->company();
        $first = $this->record($company, 'OLD', 'Same Name');
        $middle = $this->record($company, 'MID', 'Same Name');
        $last = $this->record($company, 'NEW', 'Same Name');
        $first->created_at = '2025-01-01 00:00:00';
        $first->save();
        $middle->created_at = '2026-01-01 00:00:00';
        $middle->save();
        $last->created_at = '2026-01-01 00:00:00';
        $last->save();
        $this->login($this->member($company), $company);

        $this->getJson(self::DIRECTORY.'?sort=created_desc&page=1&per_page=2')->assertOk()
            ->assertJsonPath('data.0.code', 'MID')->assertJsonPath('data.1.code', 'NEW');
        $this->getJson(self::DIRECTORY.'?sort=created_asc&page=1&per_page=3')->assertOk()
            ->assertJsonPath('data.0.code', 'OLD')->assertJsonPath('data.1.code', 'MID');
    }

    public function test_sort_filters_and_pagination_never_cross_tenant_boundary(): void
    {
        $company = $this->company();
        $other = $this->company('BETA');
        $this->record($company, 'Z', 'Estate A', false);
        $this->record($company, 'B', 'Estate B');
        $this->record($company, 'A', 'Estate C');
        $this->record($other, 'SECRET', 'Estate X');
        $this->login($this->member($company), $company);

        $this->getJson(self::DIRECTORY.'?search=Estate&status=active&sort=code_desc&page=1&per_page=1&company_id='.$other->id)
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.code', 'B')->assertDontSee('SECRET');
        $this->getJson(self::DIRECTORY.'?search=Estate&status=active&sort=code_desc&page=2&per_page=1')
            ->assertOk()->assertJsonPath('data.0.code', 'A');
    }

    public function test_csv_order_matches_filtered_directory_across_multiple_pages(): void
    {
        $company = $this->company();
        $this->record($company, 'C', 'Garden One');
        $this->record($company, 'A', 'Garden Three', false);
        $this->record($company, 'B', 'Garden Two');
        $this->login($this->member($company), $company);

        $url = '?search=Garden&status=all&sort=code_desc';
        $first = $this->getJson(self::DIRECTORY.$url.'&page=1&per_page=2')->assertOk()->json('data');
        $second = $this->getJson(self::DIRECTORY.$url.'&page=2&per_page=2')->assertOk()->json('data');
        $csv = $this->csvRows($this->get(self::EXPORT.$url)->assertOk()->getContent());
        $this->assertSame(array_column([...$first, ...$second], 'code'), array_column(array_slice($csv, 1), 0));
        $this->assertSame(['C', 'B', 'A'], array_column(array_slice($csv, 1), 0));
    }

    public function test_rejects_unsanctioned_sort_values_and_array_injection_on_both_endpoints(): void
    {
        $company = $this->company();
        $this->login($this->member($company), $company);
        foreach (['sort=id_desc', 'sort=created_at', 'sort[]=name_asc', 'sort=name%20ASC', 'sort=name_asc%3BDROP'] as $unsafe) {
            $this->getJson(self::DIRECTORY.'?page=1&'.$unsafe)->assertUnprocessable();
            $this->getJson(self::EXPORT.'?'.$unsafe)->assertUnprocessable();
        }
    }

    public function test_missing_view_permission_and_nonmembership_block_sort_and_csv(): void
    {
        $company = $this->company();
        $this->login($this->member($company, false), $company);
        $this->getJson(self::DIRECTORY.'?sort=code_asc')->assertForbidden();
        $this->getJson(self::EXPORT.'?sort=code_asc')->assertForbidden();
        $this->login(User::factory()->create(), $company);
        $this->getJson(self::DIRECTORY.'?sort=code_asc')->assertForbidden();
        $this->getJson(self::EXPORT.'?sort=code_asc')->assertForbidden();
    }

    public function test_sort_requests_are_read_only_and_do_not_modify_records(): void
    {
        $company = $this->company();
        $this->record($company, 'A', 'First');
        $this->record($company, 'B', 'Second');
        $this->login($this->member($company), $company);
        $before = PropertyDevelopment::query()->orderBy('id')->get()->toArray();
        $this->getJson(self::DIRECTORY.'?sort=created_desc&page=1')->assertOk();
        $this->get(self::EXPORT.'?sort=name_desc')->assertOk();
        $this->assertSame($before, PropertyDevelopment::query()->orderBy('id')->get()->toArray());
    }
}
