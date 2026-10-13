<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformCapabilityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'writes_enabled' => config('platform.mutations_enabled', false)
                    && $request->hasSession()
                    && $request->session()->get('platform_mfa_verified') === true,
                'mfa_required' => true,
            ],
        ]);
    }
}
