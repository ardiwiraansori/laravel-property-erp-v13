<?php

namespace Tests\Feature\Api\Platform;

use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Support\Platform\PlatformTotp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class PlatformMfaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // Refresh the named MFA attempt limiter between test cases.
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
            'Accept' => 'application/json',
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['password' => 'password']);
        PlatformAdmin::query()->create([
            'user_id' => $user->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);
        return $user;
    }

    private function code(string $secret): string
    {
        return app(PlatformTotp::class)->atStep($secret, intdiv(now()->timestamp, 30));
    }

    /** @return array{0: string, 1: array} */
    private function enroll(User $user): array
    {
        $this->actingAs($user);
        $setup = $this->postJson('/api/platform/mfa/setup', ['password' => 'password'])->assertOk();
        $secret = $setup->json('data.manual_secret');
        $confirmed = $this->postJson('/api/platform/mfa/confirm', ['code' => $this->code($secret)])
            ->assertOk()->assertJsonPath('data.verified', true);
        return [$secret, $confirmed->json('data.recovery_codes')];
    }

    public function test_guest_and_tenant_member_cannot_access_platform_mfa(): void
    {
        $this->getJson('/api/platform/mfa/status')->assertUnauthorized();
        $this->postJson('/api/platform/mfa/setup', ['password' => 'password'])->assertUnauthorized();
        $tenant = User::factory()->create();
        $tenant->companies()->attach(Company::query()->create(['code' => 'TENANT-01', 'name' => 'Tenant'])->id);
        $this->actingAs($tenant)->getJson('/api/platform/mfa/status')->assertForbidden();
        $this->postJson('/api/platform/mfa/challenge', ['code' => '123456'])->assertForbidden();
    }

    public function test_platform_without_mfa_is_denied_even_read_only_endpoints(): void
    {
        $this->actingAs($this->admin());
        $this->getJson('/api/platform/mfa/status')->assertOk()
            ->assertJsonPath('data.enrolled', false)
            ->assertJsonPath('data.verified', false);
        $this->getJson('/api/platform/me')->assertStatus(428)
            ->assertJsonPath('code', 'PLATFORM_MFA_REQUIRED');
        $this->getJson('/api/platform/companies')->assertStatus(428);
        $this->getJson('/api/platform/users')->assertStatus(428);
        $this->postJson('/api/platform/companies', ['code' => 'NEW', 'name' => 'New'])
            ->assertStatus(428);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_header_is_rejected_even_on_enrollment_endpoints(): void
    {
        $this->actingAs($this->admin())->withHeader('X-Company-ID', '1')
            ->getJson('/api/platform/mfa/status')->assertStatus(422);
        $this->postJson('/api/platform/mfa/setup', ['password' => 'password'])->assertStatus(422);
    }

    public function test_enrollment_rejects_invalid_password_without_storing_secret(): void
    {
        $this->actingAs($this->admin())->postJson('/api/platform/mfa/setup', ['password' => 'wrong'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('platform_mfa_credentials', 0);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform.mfa.setup', 'outcome' => 'denied']);
    }

    public function test_setup_creates_encrypted_seed_and_never_returns_it_on_status(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $response = $this->postJson('/api/platform/mfa/setup', ['password' => 'password'])->assertOk();
        $secret = $response->json('data.manual_secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/D', $secret);
        $this->assertStringContainsString('otpauth://totp/', $response->json('data.otpauth_uri'));
        $this->assertStringContainsString($secret, $response->json('data.otpauth_uri'));
        $row = DB::table('platform_mfa_credentials')->where('user_id', $admin->id)->first();
        $this->assertNotSame($secret, $row->encrypted_secret);
        $this->assertSame($secret, app(PlatformTotp::class)->decrypt($row->encrypted_secret));
        $this->getJson('/api/platform/mfa/status')->assertOk()
            ->assertJsonMissing(['manual_secret' => $secret]);
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform.mfa.setup', 'metadata' => null]);
    }

    public function test_confirm_requires_correct_code_and_time_bounded_setup(): void
    {
        $this->actingAs($this->admin());
        $start = $this->postJson('/api/platform/mfa/setup', ['password' => 'password'])->assertOk();
        $secret = $start->json('data.manual_secret');
        $invalidCode = $this->code($secret) === '000000' ? '000001' : '000000';
        $this->postJson('/api/platform/mfa/confirm', ['code' => $invalidCode])
            ->assertUnprocessable();
        $this->travel(301)->seconds();
        $this->postJson('/api/platform/mfa/confirm', ['code' => $this->code($secret)])
            ->assertUnprocessable();
        $this->assertDatabaseCount('platform_mfa_recovery_codes', 0);
    }

    public function test_successful_enrollment_issues_recovery_codes_once_and_unlocks_read_only(): void
    {
        $admin = $this->admin();
        [$secret, $codes] = $this->enroll($admin);
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        $this->assertDatabaseCount('platform_mfa_recovery_codes', 10);
        $this->assertDatabaseMissing('platform_mfa_recovery_codes', ['code_hash' => $codes[0]]);
        $this->getJson('/api/platform/me')->assertOk()->assertJsonPath('data.platform_access', true);
        $this->getJson('/api/platform/capabilities')->assertOk()
            ->assertJsonPath('data.writes_enabled', false);
        $this->getJson('/api/platform/mfa/status')->assertOk()
            ->assertJsonPath('data.enrolled', true)->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.recovery_codes_remaining', 10)
            ->assertDontSee($secret)->assertDontSee($codes[0]);
        $this->postJson('/api/platform/mfa/confirm', ['code' => $this->code($secret)])
            ->assertUnprocessable();
        $this->assertDatabaseCount('platform_mfa_recovery_codes', 10);
    }

    public function test_totp_code_cannot_be_replayed_and_new_step_can_challenge(): void
    {
        [$secret] = $this->enroll($this->admin());
        // Confirmation already consumed the current time-step.
        $this->postJson('/api/platform/mfa/challenge', ['code' => $this->code($secret)])
            ->assertUnprocessable();
        $this->travel(31)->seconds();
        $this->postJson('/api/platform/mfa/challenge', ['code' => $this->code($secret)])
            ->assertOk();
        $this->postJson('/api/platform/mfa/challenge', ['code' => $this->code($secret)])
            ->assertUnprocessable();
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform.mfa.challenge', 'outcome' => 'denied']);
    }

    public function test_mfa_session_expires_after_ten_minutes_and_can_be_renewed(): void
    {
        [$secret] = $this->enroll($this->admin());
        $this->getJson('/api/platform/me')->assertOk();
        $this->travel(601)->seconds();
        $this->getJson('/api/platform/me')->assertStatus(428);
        $this->postJson('/api/platform/mfa/challenge', ['code' => $this->code($secret)])
            ->assertOk();
        $this->getJson('/api/platform/me')->assertOk();
    }

    public function test_mfa_proof_must_match_authenticated_user_and_credential(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $this->enroll($a);
        $this->getJson('/api/platform/me')->assertOk();

        // Preserve the authenticated browser session. Switching actingAs() after
        // MFA rotates the session can yield 401 before the MFA guard executes,
        // which does not actually test whether a foreign MFA proof is rejected.
        $this->withSession(['platform_mfa_user_id' => (int) $b->id])
            ->getJson('/api/platform/me')
            ->assertStatus(428)
            ->assertJsonPath('code', 'PLATFORM_MFA_REQUIRED');

        // Restore the session owner but supply a different MFA credential ID.
        $this->withSession([
            'platform_mfa_user_id' => (int) $a->id,
            'platform_mfa_credential_id' => 999999,
        ])->getJson('/api/platform/me')
            ->assertStatus(428)
            ->assertJsonPath('code', 'PLATFORM_MFA_REQUIRED');
    }

    public function test_recovery_requires_password_and_valid_unused_code(): void
    {
        [$secret, $codes] = $this->enroll($this->admin());
        $this->postJson('/api/platform/mfa/recover', [
            'password' => 'bad-password', 'recovery_code' => $codes[0],
        ])->assertUnprocessable();
        $this->getJson('/api/platform/me')->assertOk();
        $this->postJson('/api/platform/mfa/recover', [
            'password' => 'password', 'recovery_code' => $codes[0],
        ])->assertOk()->assertJsonPath('data.mfa_reset', true);
        $this->assertDatabaseCount('platform_mfa_credentials', 0);
        $this->assertDatabaseCount('platform_mfa_recovery_codes', 0);
        $this->getJson('/api/platform/me')->assertStatus(428);
        $this->getJson('/api/platform/mfa/status')->assertOk()->assertJsonPath('data.enrolled', false);
        $this->postJson('/api/platform/mfa/challenge', ['code' => $this->code($secret)])
            ->assertUnprocessable();
        $this->postJson('/api/platform/mfa/recover', [
            'password' => 'password', 'recovery_code' => $codes[0],
        ])->assertUnprocessable();
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform.mfa.recovery', 'outcome' => 'success']);
    }

    public function test_recovery_invalidates_other_session_proofs(): void
    {
        [$secret, $codes] = $this->enroll($this->admin());
        $originalProof = session()->all();
        $this->postJson('/api/platform/mfa/recover', [
            'password' => 'password', 'recovery_code' => $codes[0],
        ])->assertOk();
        $this->withSession($originalProof)->getJson('/api/platform/me')->assertStatus(428);
    }

    public function test_enabled_config_with_proven_mfa_allows_audited_company_mutation(): void
    {
        config()->set('platform.mutations_enabled', true);
        $this->enroll($this->admin());
        $this->getJson('/api/platform/capabilities')->assertOk()
            ->assertJsonPath('data.writes_enabled', true);
        $this->postJson('/api/platform/companies', ['code' => 'SECURE-MFA', 'name' => 'Verified company'])
            ->assertCreated();
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform.company.create']);
        $this->assertDatabaseHas('companies', ['code' => 'SECURE-MFA']);
    }

    public function test_tenant_membership_and_revocation_override_valid_mfa_proof(): void
    {
        $admin = $this->admin();
        $this->enroll($admin);
        $this->getJson('/api/platform/me')->assertOk();
        $company = Company::query()->create(['code' => 'DENIED', 'name' => 'Denied']);
        $admin->companies()->attach($company->id);
        $this->getJson('/api/platform/me')->assertForbidden();
        $admin->companies()->detach($company->id);
        $admin->platformAdmin()->update(['revoked_at' => now()]);
        $this->getJson('/api/platform/me')->assertForbidden();
    }

    public function test_challenge_is_rate_limited(): void
    {
        $this->actingAs($this->admin());
        // One account, one IP: repeated incorrect codes cannot be brute-forced.
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/platform/mfa/challenge', ['code' => '000000'])
                ->assertUnprocessable();
        }
        $this->postJson('/api/platform/mfa/challenge', ['code' => '000000'])->assertStatus(429);
    }

    public function test_known_rfc6238_test_vector_matches_six_digit_truncation(): void
    {
        $this->assertSame('287082', app(PlatformTotp::class)->atStep(
            'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1,
        ));
    }
}
