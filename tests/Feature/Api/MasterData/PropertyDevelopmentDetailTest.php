<?php

namespace Tests\Feature\Api\MasterData;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Models\PropertyDevelopment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Checkpoint 7.20: read-only, direct-linkable detail scoped by membership and company. */
final class PropertyDevelopmentDetailTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/master-data/property-developments/';

    private function company(string $code = 'ALPHA'): Company
    {
        return Company::query()->create(['code' => $code, 'name' => 'Company '.$code]);
    }

    private function development(Company $company, string $code): PropertyDevelopment
    {
        return PropertyDevelopment::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'name' => 'Project '.$code,
        ]);
    }

    private function member(Company $company, bool $canView = true): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company->id);
        if ($canView) {
            $this->grantView($user, $company);
        }

        return $user;
    }

    private function grantView(User $user, Company $company): void
    {
        $previous = getPermissionsTeamId();
        try {
            setPermissionsTeamId($company->id);
            $permission = Permission::findOrCreate(PermissionName::MasterDataPropertyDevelopmentsView->value, 'web');
            $user->givePermissionTo($permission);
        } finally {
            setPermissionsTeamId($previous);
        }
    }

    private function login(User $user, Company $company): void
    {
        $this->actingAs($user)->withHeader('X-Company-ID', (string) $company->id);
    }

    public function test_authorized_viewer_can_open_detail_with_timestamps(): void
    {
        $company = $this->company();
        $record = $this->development($company, 'HOME');
        $this->login($this->member($company), $company);

        $this->getJson(self::URL.$record->id)->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.code', 'HOME')
            ->assertJsonPath('data.name', 'Project HOME')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonStructure(['data' => ['id', 'code', 'name', 'is_active', 'created_at', 'updated_at']])
            ->assertJsonMissingPath('data.company_id')
            ->assertJsonMissingPath('meta');
    }

    public function test_unknown_record_returns_not_found(): void
    {
        $company = $this->company();
        $this->login($this->member($company), $company);
        $this->getJson(self::URL.'999999')->assertNotFound();
    }

    public function test_cross_company_record_is_hidden_as_not_found(): void
    {
        $first = $this->company();
        $other = $this->company('BETA');
        $record = $this->development($other, 'SECRET');
        $this->login($this->member($first), $first);

        $this->getJson(self::URL.$record->id)->assertNotFound()->assertDontSee('SECRET');
    }

    public function test_read_permission_cannot_be_inferred_from_membership_or_create_permission(): void
    {
        $company = $this->company();
        $record = $this->development($company, 'ONE');
        $user = $this->member($company, false);
        $previous = getPermissionsTeamId();
        try {
            setPermissionsTeamId($company->id);
            $user->givePermissionTo(Permission::findOrCreate(PermissionName::MasterDataPropertyDevelopmentsCreate->value, 'web'));
        } finally {
            setPermissionsTeamId($previous);
        }
        $this->login($user, $company);
        $this->getJson(self::URL.$record->id)->assertForbidden();
    }

    public function test_guest_nonmember_and_missing_header_are_denied(): void
    {
        $company = $this->company();
        $record = $this->development($company, 'ONE');
        $this->getJson(self::URL.$record->id)->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->withHeader('X-Company-ID', (string) $company->id)
            ->getJson(self::URL.$record->id)->assertForbidden();

        $this->login($this->member($company), $company);
        $this->withHeaders(['X-Company-ID' => ''])
            ->getJson(self::URL.$record->id)->assertUnprocessable();
    }

    public function test_same_user_two_companies_requires_view_permission_for_each_company(): void
    {
        $first = $this->company();
        $second = $this->company('BETA');
        $firstRecord = $this->development($first, 'FIRST');
        $secondRecord = $this->development($second, 'SECOND');
        $user = $this->member($first);
        $user->companies()->attach($second->id);
        $this->login($user, $first);

        $this->getJson(self::URL.$firstRecord->id)->assertOk();
        $this->withHeader('X-Company-ID', (string) $second->id);
        $this->getJson(self::URL.$secondRecord->id)->assertForbidden();

        $this->grantView($user, $second);
        $this->getJson(self::URL.$secondRecord->id)->assertOk()
            ->assertJsonPath('data.code', 'SECOND');
        $this->getJson(self::URL.$firstRecord->id)->assertNotFound();
    }

    public function test_query_parameters_cannot_override_server_resolved_company(): void
    {
        $first = $this->company();
        $other = $this->company('BETA');
        $firstRecord = $this->development($first, 'FIRST');
        $otherRecord = $this->development($other, 'PRIVATE');
        $this->login($this->member($first), $first);

        $this->getJson(self::URL.$firstRecord->id.'?company_id='.$other->id)
            ->assertOk()->assertJsonPath('data.code', 'FIRST');
        $this->getJson(self::URL.$otherRecord->id.'?company_id='.$other->id)
            ->assertNotFound();
    }

    public function test_inactive_company_cannot_open_detail(): void
    {
        $company = $this->company();
        $record = $this->development($company, 'ONE');
        $user = $this->member($company);
        $company->update(['is_active' => false]);
        $this->login($user, $company);
        $this->getJson(self::URL.$record->id)->assertForbidden();
    }

    public function test_detail_is_read_only_and_does_not_change_record(): void
    {
        $company = $this->company();
        $record = $this->development($company, 'ONE');
        $this->login($this->member($company), $company);
        $before = $record->fresh()->toArray();

        $this->getJson(self::URL.$record->id)->assertOk();
        $this->assertSame($before, $record->fresh()->toArray());
    }

    public function test_summary_route_stays_distinct_from_numeric_detail_route(): void
    {
        $company = $this->company();
        $this->development($company, 'ONE');
        $this->login($this->member($company), $company);

        $this->getJson(self::URL.'summary')->assertOk()
            ->assertJsonPath('data.total', 1);
        $this->getJson(self::URL.'not-a-number')->assertNotFound();
    }
}
