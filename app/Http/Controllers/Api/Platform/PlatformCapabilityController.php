<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Support\Platform\PlatformMfaSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformCapabilityController extends Controller
{
    public function show(Request $request, PlatformMfaSession $mfa): JsonResponse
    {
        return response()->json([
            'data' => [
                'writes_enabled' => config('platform.mutations_enabled', false)
                    && $mfa->verifiedUntil($request) !== null,
                'mfa_required' => true,
            ],
        ]);
    }
}
