<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail closed: successful Sanctum authentication is not sufficient to mutate
 * global platform records. The session proof has no issuing route yet.
 */
final class EnsurePlatformWriteEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('platform.mutations_enabled', false)
            || ! $request->hasSession()
            || $request->session()->get('platform_mfa_verified') !== true) {
            return response()->json([
                'message' => 'Platform changes are locked until the MFA workflow is enabled.',
                'code' => 'PLATFORM_WRITE_LOCKED',
            ], 423);
        }

        return $next($request);
    }
}
