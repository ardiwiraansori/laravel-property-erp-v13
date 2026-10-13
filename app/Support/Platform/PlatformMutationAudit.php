<?php

namespace App\Support\Platform;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Append-only, allowlisted events. Call inside the same DB transaction. */
final class PlatformMutationAudit
{
    private const ACTIONS = [
        'platform.company.create' => 'company',
        'platform.company.update' => 'company',
        'platform.user.update' => 'user',
        'platform.membership.attach' => 'user',
        'platform.membership.detach' => 'user',
    ];

    public function record(Request $request, string $action, int $targetId, ?int $companyId = null): void
    {
        $targetType = self::ACTIONS[$action] ?? null;
        if ($targetType === null) {
            throw new InvalidArgumentException('Unsupported platform mutation action.');
        }

        DB::table('platform_audit_logs')->insert([
            'event_id' => (string) Str::ulid(),
            'request_id' => (string) Str::ulid(),
            'actor_user_id' => $request->user()->getAuthIdentifier(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'company_id' => $companyId,
            'outcome' => 'success',
            'reason' => null,
            'metadata' => null,
            'created_at' => now(),
        ]);
    }
}
