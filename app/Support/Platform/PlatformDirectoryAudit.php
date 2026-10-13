<?php

namespace App\Support\Platform;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Append-only access audit: no response bodies, search text, or credentials. */
final class PlatformDirectoryAudit
{
    private const ACTIONS = [
        'platform.companies.read',
        'platform.users.read',
        'platform.user_memberships.read',
        'platform.diagnostics.read',
    ];

    public function record(Request $request, string $action, ?User $subject = null): string
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Unsupported platform audit action.');
        }

        $requestId = (string) Str::ulid();
        DB::table('platform_audit_logs')->insert([
            'event_id' => (string) Str::ulid(),
            'request_id' => $requestId,
            'actor_user_id' => $request->user()->getAuthIdentifier(),
            'action' => $action,
            'target_type' => $subject === null ? null : 'user',
            'target_id' => $subject?->getKey(),
            'company_id' => null,
            'outcome' => 'success',
            'reason' => null,
            'metadata' => null,
            'created_at' => now(),
        ]);
        return $requestId;
    }
}
