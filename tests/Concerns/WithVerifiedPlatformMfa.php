<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Support\Platform\PlatformTotp;
use Illuminate\Support\Facades\DB;

/** Feature tests that cover directory behavior after platform MFA succeeded. */
trait WithVerifiedPlatformMfa
{
    protected function withVerifiedPlatformMfa(User $user): void
    {
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
            'Accept' => 'application/json',
        ]);
        $credentialId = DB::table('platform_mfa_credentials')->insertGetId([
            'user_id' => $user->id,
            'encrypted_secret' => app(PlatformTotp::class)->encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'confirmed_at' => now(),
            'last_used_step' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->withSession([
            'platform_mfa_verified' => true,
            'platform_mfa_verified_at' => now()->timestamp,
            'platform_mfa_user_id' => (int) $user->id,
            'platform_mfa_credential_id' => (int) $credentialId,
        ]);
    }
}
