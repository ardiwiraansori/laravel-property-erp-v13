<?php

namespace Tests\Feature\Api\MasterData;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Models\PropertyDevelopment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Read-only, tenant-safe CSV export with bounded results and spreadsheet-safe cells. */
final class PropertyDevelopmentCsvExportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/master-data/property-developments/export';

    private function company(string $code = 'ALPHA'): Company
    {
        return Company::query()->create(['code' => $code, 'name' => 'Company '.$code]);
    }

    private function development(Company $company, string $code, string $name, bool $active = true): PropertyDevelopment
    {
        return PropertyDevelopment::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'name' => $name,
            'is_active' => $active,
        ]);
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

    private function member(Company $company, bool $grant = true): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company->id);
        if ($grant) {
            $this->grantView($user, $company);
        }

        return $user;
    }

    private function login(User $user, Company $company): void
    {
        $this->actingAs($user)->withHeader('X-Company-ID', (string) $company->id);
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, substr($content, 3));
        rewind($handle);
        $rows = [];
        while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $record;
        }
        fclose($handle);

        return $rows;
    }

    public function test_empty_company_returns_only_safe_utf8_header(): void
    {
        $company = $this->company();
        $this->login($this->member($company), $company);
        $response = $this->get(self::URL)->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="property-developments.csv"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // Symfony normalizes Cache-Control directive order; check the exact
        // required directive set instead of the serialized header order.
        $cacheDirectives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        sort($cacheDirectives);
        $this->assertSame(['max-age=0', 'no-store', 'private'], $cacheDirectives);
        $this->assertSame([['Code', 'Name', 'Status', 'Created at (UTC)']], $this->csvRows($response->getContent()));
    }

    public function test_export_contains_active_and_inactive_rows_in_stable_name_order(): void
    {
        $company = $this->company();
        $this->development($company, 'Z', 'Zebra', false);
        $this->development($company, 'A', 'Aspen');
        $this->development($company, 'B', 'Birch');
        $this->login($this->member($company), $company);

        $rows = $this->csvRows($this->get(self::URL)->assertOk()->getContent());
        $this->assertCount(4, $rows);
        $this->assertSame(['A', 'B', 'Z'], array_column(array_slice($rows, 1), 0));
        $this->assertSame('Inactive', $rows[3][2]);
        $this->assertStringEndsWith('Z', $rows[1][3]);
    }

    public function test_active_inactive_and_all_status_filters_are_consistent_with_directory(): void
    {
        $company = $this->company();
        $this->development($company, 'A', 'Active');
        $this->development($company, 'I', 'Inactive', false);
        $this->login($this->member($company), $company);

        $this->assertSame('A', $this->csvRows($this->get(self::URL.'?status=active')->assertOk()->getContent())[1][0]);
        $this->assertCount(2, $this->csvRows($this->get(self::URL.'?status=inactive')->assertOk()->getContent()));
        $this->assertCount(3, $this->csvRows($this->get(self::URL.'?status=all')->assertOk()->getContent()));
    }

    public function test_search_matches_code_and_name_case_insensitively_with_edge_whitespace(): void
    {
        $company = $this->company();
        $this->development($company, 'GRIYA-01', 'Serene Gardens');
        $this->development($company, 'OTHER', 'Orchid');
        $this->login($this->member($company), $company);

        $this->assertSame('GRIYA-01', $this->csvRows($this->get(self::URL.'?search=gRiYa')->assertOk()->getContent())[1][0]);
        $this->assertSame('GRIYA-01', $this->csvRows($this->get(self::URL.'?search='.urlencode(' serene '))->assertOk()->getContent())[1][0]);
    }

    public function test_search_treats_percent_underscore_and_bang_as_literals(): void
    {
        $company = $this->company();
        $this->development($company, 'PERCENT', 'Palm % Avenue');
        $this->development($company, 'UNDER', 'Palm _ Avenue');
        $this->development($company, 'EXCLAIM', 'Palm ! Avenue');
        $this->development($company, 'OTHER', 'Palm A Avenue');
        $this->login($this->member($company), $company);

        foreach (['%25' => 'PERCENT', '_' => 'UNDER', '!' => 'EXCLAIM'] as $search => $expected) {
            $rows = $this->csvRows($this->get(self::URL.'?search='.$search)->assertOk()->getContent());
            $this->assertCount(2, $rows);
            $this->assertSame($expected, $rows[1][0]);
        }
    }

    public function test_export_includes_all_filtered_rows_not_only_the_first_page(): void
    {
        $company = $this->company();
        foreach (range(1, 31) as $i) {
            $this->development($company, 'ROW'.$i, sprintf('Housing %03d', $i));
        }
        $this->login($this->member($company), $company);
        $this->assertCount(32, $this->csvRows($this->get(self::URL)->assertOk()->getContent()));
        $this->getJson(self::URL.'?page=2')->assertUnprocessable();
        $this->getJson(self::URL.'?per_page=10')->assertUnprocessable();
    }

    public function test_other_company_records_are_never_included(): void
    {
        $company = $this->company();
        $other = $this->company('BETA');
        $this->development($company, 'PUBLIC', 'Shared');
        $this->development($other, 'SECRET', 'Shared');
        $this->login($this->member($company), $company);

        $body = $this->get(self::URL)->assertOk()->getContent();
        $this->assertCount(2, $this->csvRows($body));
        $this->assertStringNotContainsString('SECRET', $body);
    }

    public function test_same_user_two_companies_require_view_permission_in_each_selected_company(): void
    {
        $first = $this->company();
        $second = $this->company('BETA');
        $this->development($first, 'FIRST', 'Shared');
        $this->development($second, 'SECOND', 'Shared');
        $user = $this->member($first);
        $user->companies()->attach($second->id);
        $this->login($user, $first);

        $this->assertSame('FIRST', $this->csvRows($this->get(self::URL)->assertOk()->getContent())[1][0]);
        $this->withHeader('X-Company-ID', (string) $second->id);
        $this->getJson(self::URL)->assertForbidden();
        $this->grantView($user, $second);
        $this->assertSame('SECOND', $this->csvRows($this->get(self::URL)->assertOk()->getContent())[1][0]);
    }

    public function test_query_company_id_cannot_override_server_resolved_tenant(): void
    {
        $company = $this->company();
        $other = $this->company('BETA');
        $this->development($company, 'OWN', 'Owned');
        $this->development($other, 'FOREIGN', 'Foreign');
        $this->login($this->member($company), $company);

        $body = $this->get(self::URL.'?company_id='.$other->id)->assertOk()->getContent();
        $this->assertCount(2, $this->csvRows($body));
        $this->assertStringContainsString('OWN', $body);
        $this->assertStringNotContainsString('FOREIGN', $body);
    }

    public function test_guest_nonmember_missing_header_and_unprivileged_member_are_denied(): void
    {
        $company = $this->company();
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->withHeader('X-Company-ID', (string) $company->id)
            ->getJson(self::URL)->assertForbidden();
        $this->login($this->member($company, false), $company);
        $this->getJson(self::URL)->assertForbidden();
        $this->withHeaders(['X-Company-ID' => ''])->getJson(self::URL)->assertUnprocessable();
    }

    public function test_inactive_company_cannot_export(): void
    {
        $company = $this->company();
        $user = $this->member($company);
        $company->update(['is_active' => false]);
        $this->login($user, $company);
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_invalid_filters_and_array_injection_are_rejected(): void
    {
        $company = $this->company();
        $this->login($this->member($company), $company);
        foreach (['status=deleted', 'search[]=bad', 'status[]=active', 'search='.str_repeat('A', 101)] as $args) {
            $this->getJson(self::URL.'?'.$args)->assertUnprocessable();
        }
    }

    public function test_csv_formula_injection_is_neutralized_without_losing_unicode_or_quotes(): void
    {
        $company = $this->company();
        $this->development($company, '-RISK', '  =SUM(1,1)');
        $this->development($company, 'AT', "\t@HYPERLINK(1)");
        $this->development($company, 'PLUS', ' +cmd');
        $this->development($company, 'UNICODE', 'Rumah "Bintang", Padang — Sumbar');
        $this->login($this->member($company), $company);

        $rows = $this->csvRows($this->get(self::URL)->assertOk()->getContent());
        $this->assertCount(5, $rows);
        $data = [];
        foreach (array_slice($rows, 1) as $row) {
            $data[$row[0]] = $row;
        }
        $this->assertSame("'-RISK", $data["'-RISK"][0]);
        $this->assertSame("'  =SUM(1,1)", $data["'-RISK"][1]);
        $this->assertSame("' @HYPERLINK(1)", $data['AT'][1]);
        $this->assertSame("' +cmd", $data['PLUS'][1]);
        $this->assertSame('Rumah "Bintang", Padang — Sumbar', $data['UNICODE'][1]);
    }

    public function test_over_maximum_row_count_returns_validation_error_not_truncated_csv(): void
    {
        $company = $this->company();
        $user = $this->member($company);
        $now = now();
        $bulk = [];
        for ($i = 1; $i <= 1001; $i++) {
            $bulk[] = [
                'company_id' => $company->id,
                'code' => 'H'.$i,
                'name' => 'Housing '.sprintf('%04d', $i),
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($bulk, 100) as $chunk) {
            PropertyDevelopment::query()->insert($chunk);
        }
        $this->login($user, $company);

        $this->getJson(self::URL)->assertUnprocessable()
            ->assertJsonPath('message', 'Too many matching developments. Narrow your filters (maximum 1000 records).');
        $this->assertDatabaseCount('property_developments', 1001);
    }

    public function test_export_is_read_only_and_does_not_change_any_records(): void
    {
        $company = $this->company();
        $record = $this->development($company, 'KEEP', 'Keep');
        $this->login($this->member($company), $company);
        $before = $record->fresh()->toArray();
        $this->get(self::URL)->assertOk();
        $this->assertSame($before, $record->fresh()->toArray());
    }
}
