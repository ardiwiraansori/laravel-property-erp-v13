<?php

namespace App\Support\Platform;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Audit identifiers only; never log passwords, TOTP seeds, codes, or IP addresses. */
final class PlatformMfaAudit
{
    private const ACTIONS = [
        'platform.mfa.setup', 'platform.mfa.confirm',
        'platform.mfa.challenge', 'platform.mfa.recovery',
    ];

    public function record(Request $request, string $action, string $outcome): void
    {
        if (! in_array($action, self::ACTIONS, true) || ! in_array($outcome, ['success', 'denied'], true)) {
            throw new InvalidArgumentException('Unsupported MFA audit action or outcome.');
        }
        DB::table('platform_audit_logs')->insert([
            'event_id' => (string) Str::ulid(),
            'request_id' => (string) Str::ulid(),
            'actor_user_id' => $request->user()->getAuthIdentifier(),
            'action' => $action,
            'target_type' => 'user',
            'target_id' => $request->user()->getAuthIdentifier(),
            'company_id' => null,
            'outcome' => $outcome,
            'reason' => null,
            'metadata' => null,
            'created_at' => now(),
        ]);
    }
}
