<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Platform\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePlatformAdmin
{
    public function __construct(private readonly PlatformAccess $platformAccess)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->platformAccess->allows($user)) {
            return response()->json(['message' => 'Platform access denied.'], 403);
        }

        // Platform requests must never resolve or inherit a tenant context.
        if ($request->hasHeader('X-Company-ID')) {
            return response()->json([
                'message' => 'X-Company-ID is not accepted on platform endpoints.',
            ], 422);
        }

        return $next($request);
    }
}
