<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformMfaCredential;
use App\Support\Platform\PlatformMfaAudit;
use App\Support\Platform\PlatformMfaSession;
use App\Support\Platform\PlatformTotp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Session-bound MFA for dedicated, already-authorized platform identities. */
final class PlatformMfaController extends Controller
{
    public function __construct(
        private readonly PlatformMfaSession $session,
        private readonly PlatformTotp $totp,
        private readonly PlatformMfaAudit $audit,
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $credential = PlatformMfaCredential::query()->where('user_id', $userId)->first();
        $until = $this->session->verifiedUntil($request);
        return response()->json(['data' => [
            'enrolled' => $credential?->confirmed_at !== null,
            'setup_pending' => $credential !== null && $credential->confirmed_at === null,
            'verified' => $until !== null,
            'expires_at' => $until === null ? null : gmdate('c', $until),
            'recovery_codes_remaining' => $credential?->confirmed_at === null ? 0 :
                DB::table('platform_mfa_recovery_codes')->where('user_id', $userId)
                    ->whereNull('used_at')->count(),
        ]])->header('Cache-Control', 'no-store');
    }

    public function setup(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:1024']]);
        if (! Hash::check($data['password'], $request->user()->getAuthPassword())) {
            $this->audit->record($request, 'platform.mfa.setup', 'denied');
            throw ValidationException::withMessages(['password' => ['Invalid credentials.']]);
        }

        $user = $request->user();
        $secret = $this->totp->newSecret();
        $credential = DB::transaction(function () use ($user, $secret, $request): PlatformMfaCredential {
            // Lock the user row, also serializing new enrollment attempts.
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $current = PlatformMfaCredential::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($current?->confirmed_at !== null) {
                abort(409, 'MFA already enrolled; use your authenticator or recovery code.');
            }
            if ($current === null) {
                $current = new PlatformMfaCredential();
                $current->user_id = $user->id;
            }
            $current->encrypted_secret = $this->totp->encrypt($secret);
            $current->confirmed_at = null;
            $current->last_used_step = null;
            $current->save();
            $this->audit->record($request, 'platform.mfa.setup', 'success');
            return $current;
        });
        $request->session()->put([
            'platform_mfa_setup_id' => (int) $credential->id,
            'platform_mfa_setup_at' => now()->timestamp,
        ]);
        return response()->json(['data' => [
            'manual_secret' => $secret,
            'otpauth_uri' => $this->totp->uri($secret, $user->email),
            'expires_in_seconds' => 300,
        ]])->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'regex:/^[0-9]{6}$/D']]);
        $setupId = $request->session()->get('platform_mfa_setup_id');
        $setupAt = $request->session()->get('platform_mfa_setup_at');
        if (! is_int($setupId) || ! is_int($setupAt) ||
            $setupAt > now()->timestamp || $setupAt + 300 < now()->timestamp) {
            throw ValidationException::withMessages(['code' => ['Setup expired. Start enrollment again.']]);
        }

        $result = DB::transaction(function () use ($request, $data, $setupId): array {
            $credential = PlatformMfaCredential::query()->where('user_id', $request->user()->id)
                ->lockForUpdate()->first();
            if (! $credential || $credential->confirmed_at !== null || $credential->id !== $setupId) {
                throw ValidationException::withMessages(['code' => ['Enrollment is no longer valid.']]);
            }
            $step = $this->totp->acceptedStep(
                $this->totp->decrypt($credential->encrypted_secret), $data['code'], $credential->last_used_step,
            );
            if ($step === null) {
                throw ValidationException::withMessages(['code' => ['Invalid or already-used authentication code.']]);
            }
            $credential->last_used_step = $step;
            $credential->confirmed_at = now();
            $credential->save();

            $codes = $this->totp->recoveryCodes();
            foreach ($codes as $code) {
                DB::table('platform_mfa_recovery_codes')->insert([
                    'user_id' => $credential->user_id,
                    'code_hash' => $this->totp->recoveryHash($code),
                    'used_at' => null,
                    'created_at' => now(),
                ]);
            }
            $this->audit->record($request, 'platform.mfa.confirm', 'success');
            return [$credential, $codes];
        });
        [$credential, $codes] = $result;
        $this->session->grant($request, $credential);
        return response()->json(['data' => [
            'verified' => true,
            'recovery_codes' => $codes,
            'message' => 'Store recovery codes securely. They will never be shown again.',
        ]])->header('Cache-Control', 'no-store');
    }

    public function challenge(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'regex:/^[0-9]{6}$/D']]);
        try {
            $credential = DB::transaction(function () use ($request, $data): PlatformMfaCredential {
                $credential = PlatformMfaCredential::query()->where('user_id', $request->user()->id)
                    ->whereNotNull('confirmed_at')->lockForUpdate()->first();
                if (! $credential) {
                    throw ValidationException::withMessages(['code' => ['MFA enrollment is required.']]);
                }
                $step = $this->totp->acceptedStep(
                    $this->totp->decrypt($credential->encrypted_secret), $data['code'], $credential->last_used_step,
                );
                if ($step === null) {
                    throw ValidationException::withMessages(['code' => ['Invalid or already-used authentication code.']]);
                }
                $credential->last_used_step = $step;
                $credential->save();
                $this->audit->record($request, 'platform.mfa.challenge', 'success');
                return $credential;
            });
        } catch (ValidationException $exception) {
            $this->audit->record($request, 'platform.mfa.challenge', 'denied');
            throw $exception;
        }
        $this->session->grant($request, $credential);
        return response()->json(['data' => ['verified' => true, 'expires_at' => gmdate('c', now()->timestamp + 600)]])
            ->header('Cache-Control', 'no-store');
    }

    /** A recovery code resets the second factor; it never authorizes mutations directly. */
    public function recover(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:1024'],
            'recovery_code' => ['required', 'string', 'max:80'],
        ]);
        $hash = $this->totp->recoveryHash($data['recovery_code']);
        if (! $hash || ! Hash::check($data['password'], $request->user()->getAuthPassword())) {
            $this->audit->record($request, 'platform.mfa.recovery', 'denied');
            throw ValidationException::withMessages(['recovery_code' => ['Invalid recovery credentials.']]);
        }
        try {
            DB::transaction(function () use ($request, $hash): void {
                $userId = (int) $request->user()->id;
                $credential = PlatformMfaCredential::query()->where('user_id', $userId)
                    ->whereNotNull('confirmed_at')->lockForUpdate()->first();
                $code = DB::table('platform_mfa_recovery_codes')->where('user_id', $userId)
                    ->where('code_hash', $hash)->whereNull('used_at')->lockForUpdate()->first();
                if (! $credential || ! $code) {
                    throw ValidationException::withMessages(['recovery_code' => ['Invalid recovery credentials.']]);
                }
                // Credential removal invalidates MFA proof in ALL other sessions.
                DB::table('platform_mfa_recovery_codes')->where('user_id', $userId)->delete();
                $credential->delete();
                $this->audit->record($request, 'platform.mfa.recovery', 'success');
            });
        } catch (ValidationException $exception) {
            $this->audit->record($request, 'platform.mfa.recovery', 'denied');
            throw $exception;
        }
        $this->session->forget($request);
        return response()->json(['data' => [
            'mfa_reset' => true,
            'message' => 'MFA reset. Re-enroll a new authenticator before accessing platform data.',
        ]])->header('Cache-Control', 'no-store');
    }
}
