<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformIdentityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        // The platform.admin middleware has already verified the authenticated user.
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'platform_access' => true,
            ],
        ]);
    }
}
