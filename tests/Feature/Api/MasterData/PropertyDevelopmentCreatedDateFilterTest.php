<?php

namespace Tests\Feature\Api\MasterData;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Models\PropertyDevelopment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Checkpoint 7.25: validated inclusive UTC creation dates, consistently applied to directory and CSV. */
final class PropertyDevelopmentCreatedDateFilterTest extends TestCase
{
    use RefreshDatabase;

    private const DIRECTORY = '/api/master-data/property-developments';
    private const EXPORT = '/api/master-data/property-developments/export';

    private function company(string $code = 'ALPHA'): Company
    {
        return Company::query()->create(['code' => $code, 'name' => 'Company '.$code]);
    }

    private function record(Company $company, string $code, string $createdAt, bool $active = true): PropertyDevelopment
    {
        $row = PropertyDevelopment::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'name' => $code.' Residence',
            'is_active' => $active,
        ]);
        $row->created_at = $createdAt;
        $row->save();

        return $row;
    }

    private function member(Company $company, bool $view = true): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company->id);
        if ($view) {
            $previous = getPermissionsTeamId();
            try {
                setPermissionsTeamId($company->id);
                $user->givePermissionTo(Permission::findOrCreate(PermissionName::MasterDataPropertyDevelopmentsView->value, 'web'));
            } finally {
                setPermissionsTeamId($previous);
            }
        }

        return $user;
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

    public function test_legacy_get_without_parameters_remains_unpaginated(): void
    {
        $company = $this->company();
        $this->record($company, 'JAN', '2026-01-01 10:00:00');
        $this->record($company, 'FEB', '2026-02-01 10:00:00');
        $this->login($this->member($company), $company);

        $this->getJson(self::DIRECTORY)->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('meta');
        $this->getJson(self::DIRECTORY.'?created_from=2026-02-01')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'FEB');
    }

    public function test_created_from_is_inclusive_of_first_day_and_excludes_earlier_records(): void
    {
        $company = $this->company();
        $this->record($company, 'BEFORE', '2026-01-31 23:59:59');
        $this->record($company, 'FIRST', '2026-02-01 00:00:00');
        $this->record($company, 'AFTER', '2026-02-02 00:00:00');
        $this->login($this->member($company), $company);

        $this->getJson(self::DIRECTORY.'?created_from=2026-02-01')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonMissing(['code' => 'BEFORE']);
    }

    public function test_created_to_is_inclusive_of_last_second(): void
    {
        $company = $this->company();
        $this->record($company, 'LAST', '2026-02-01 23:59:59');
        $this->record($company, 'NEXT', '2026-02-02 00:00:00');
        $this->login($this->member($company), $company);

        $this->getJson(self::DIRECTORY.'?created_to=2026-02-01')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'LAST');
    }

    public function test_same_day_range_and_sort_status_and_page_are_composable(): void
    {
        $company = $this->company();
        $this->record($company, 'A', '2026-02-01 10:00:00');
        $this->record($company, 'B', '2026-02-01 20:00:00');
        $this->record($company, 'C', '2026-02-01 21:00:00', false);
        $this->record($company, 'D', '2026-02-02 00:00:00');
        $this->login($this->member($company), $company);

        $url = '?created_from=2026-02-01&created_to=2026-02-01&status=active&sort=code_desc';
        $this->getJson(self::DIRECTORY.$url.'&page=1&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.code', 'B');
        $this->getJson(self::DIRECTORY.$url.'&page=2&per_page=1')->assertOk()
            ->assertJsonPath('data.0.code', 'A');
    }

    public function test_date_filters_and_csv_never_cross_company_boundary(): void
    {
        $company = $this->company();
        $other = $this->company('BETA');
        $this->record($company, 'VISIBLE', '2026-02-01 12:00:00');
        $this->record($other, 'SECRET', '2026-02-01 12:00:00');
        $this->login($this->member($company), $company);

        $url = '?created_from=2026-02-01&created_to=2026-02-01&company_id='.$other->id;
        $this->getJson(self::DIRECTORY.$url)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'VISIBLE')->assertDontSee('SECRET');
        $body = $this->get(self::EXPORT.$url)->assertOk()->getContent();
        $this->assertSame(['VISIBLE'], array_column(array_slice($this->csvRows($body), 1), 0));
    }

    public function test_csv_and_paginated_directory_return_matching_filtered_order(): void
    {
        $company = $this->company();
        $this->record($company, 'A', '2026-01-31 23:59:59');
        $this->record($company, 'B', '2026-02-01 00:00:00');
        $this->record($company, 'C', '2026-02-02 23:59:59');
        $this->record($company, 'D', '2026-02-03 00:00:00');
        $this->login($this->member($company), $company);

        $url = '?created_from=2026-02-01&created_to=2026-02-02&sort=code_desc';
        $rows = $this->getJson(self::DIRECTORY.$url.'&page=1&per_page=1')->assertOk()->json('data');
        $rows = [...$rows, ...$this->getJson(self::DIRECTORY.$url.'&page=2&per_page=1')->assertOk()->json('data')];
        $csv = $this->csvRows($this->get(self::EXPORT.$url)->assertOk()->getContent());
        $this->assertSame(['C', 'B'], array_column($rows, 'code'));
        $this->assertSame(array_column($rows, 'code'), array_column(array_slice($csv, 1), 0));
    }

    public function test_invalid_dates_are_rejected_on_directory_and_csv(): void
    {
        $company = $this->company();
        $this->login($this->member($company), $company);

        foreach ([
            'created_from=2026-02-30', 'created_to=2026-02-30',
            'created_from=2026-2-01', 'created_to=not-a-date',
            'created_from[]=2026-01-01', 'created_to[]=2026-12-31',
            'created_from=2026-02-02&created_to=2026-02-01',
        ] as $params) {
            $this->getJson(self::DIRECTORY.'?'.$params)->assertUnprocessable();
            $this->getJson(self::EXPORT.'?'.$params)->assertUnprocessable();
        }
    }

    public function test_authorization_remains_required_for_date_filtered_queries(): void
    {
        $company = $this->company();
        $this->login($this->member($company, false), $company);
        $this->getJson(self::DIRECTORY.'?created_from=2026-01-01')->assertForbidden();
        $this->getJson(self::EXPORT.'?created_to=2026-12-31')->assertForbidden();
        $this->login(User::factory()->create(), $company);
        $this->getJson(self::DIRECTORY.'?created_from=2026-01-01')->assertForbidden();
        $this->getJson(self::EXPORT.'?created_to=2026-12-31')->assertForbidden();
    }

    public function test_date_filtering_does_not_write_business_records(): void
    {
        $company = $this->company();
        $this->record($company, 'A', '2026-02-01 10:00:00');
        $this->record($company, 'B', '2026-02-02 10:00:00');
        $this->login($this->member($company), $company);
        $before = PropertyDevelopment::query()->orderBy('id')->get()->toArray();

        $this->getJson(self::DIRECTORY.'?created_from=2026-02-01&created_to=2026-02-02')->assertOk();
        $this->get(self::EXPORT.'?created_from=2026-02-01&created_to=2026-02-02')->assertOk();
        $this->assertSame($before, PropertyDevelopment::query()->orderBy('id')->get()->toArray());
    }
}
