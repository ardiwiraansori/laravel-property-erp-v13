<?php

namespace App\Http\Middleware;

use App\Support\Platform\PlatformMfaSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail closed: successful Sanctum authentication is not sufficient to mutate
 * global platform records. The MFA proof must be fresh and bound to the credential.
 */
final class EnsurePlatformWriteEnabled
{
    public function __construct(private readonly PlatformMfaSession $mfa)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('platform.mutations_enabled', false)
            || $this->mfa->verifiedUntil($request) === null) {
            return response()->json([
                'message' => 'Platform changes require fresh MFA and an explicit platform write enablement.',
                'code' => 'PLATFORM_WRITE_LOCKED',
            ], 423);
        }

        return $next($request);
    }
}
