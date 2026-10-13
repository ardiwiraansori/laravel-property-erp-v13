<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** MFA enrollment and challenges require a CSRF-protected browser session. */
final class EnsurePlatformBrowserSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'A browser session is required for platform MFA.',
                'code' => 'PLATFORM_SESSION_REQUIRED',
            ], 403);
        }
        return $next($request);
    }
}
