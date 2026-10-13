<?php

namespace App\Support\Platform;

use App\Models\PlatformMfaCredential;
use App\Models\User;
use Illuminate\Http\Request;

final class PlatformMfaSession
{
    public function credential(User $user): ?PlatformMfaCredential
    {
        return PlatformMfaCredential::query()->where('user_id', $user->id)
            ->whereNotNull('confirmed_at')->first();
    }

    public function verifiedUntil(Request $request): ?int
    {
        if (! $request->hasSession() || ! ($request->user() instanceof User)) {
            return null;
        }
        $user = $request->user();
        $credential = $this->credential($user);
        $session = $request->session();
        $verifiedAt = $session->get('platform_mfa_verified_at');
        $expiresAt = is_int($verifiedAt) ? $verifiedAt + 600 : 0;
        if (! $credential
            || $session->get('platform_mfa_verified') !== true
            || (int) $session->get('platform_mfa_user_id') !== (int) $user->id
            || (int) $session->get('platform_mfa_credential_id') !== (int) $credential->id
            || ! is_int($verifiedAt)
            || $verifiedAt > now()->timestamp
            || $expiresAt <= now()->timestamp) {
            return null;
        }
        return $expiresAt;
    }

    public function grant(Request $request, PlatformMfaCredential $credential): void
    {
        // Rotate the session identifier on privilege elevation, preserving auth.
        $request->session()->migrate(true);
        $request->session()->put([
            'platform_mfa_verified' => true,
            'platform_mfa_verified_at' => now()->timestamp,
            'platform_mfa_user_id' => (int) $credential->user_id,
            'platform_mfa_credential_id' => (int) $credential->id,
        ]);
        $request->session()->forget(['platform_mfa_setup_id', 'platform_mfa_setup_at']);
    }

    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget([
                'platform_mfa_verified', 'platform_mfa_verified_at',
                'platform_mfa_user_id', 'platform_mfa_credential_id',
                'platform_mfa_setup_id', 'platform_mfa_setup_at',
            ]);
        }
    }
}
