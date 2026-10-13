<?php

namespace App\Http\Middleware;

use App\Support\Platform\PlatformMfaSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePlatformMfa
{
    public function __construct(private readonly PlatformMfaSession $mfa)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->mfa->verifiedUntil($request) === null) {
            return response()->json([
                'message' => 'Platform MFA verification is required.',
                'code' => 'PLATFORM_MFA_REQUIRED',
            ], 428);
        }
        return $next($request);
    }
}
