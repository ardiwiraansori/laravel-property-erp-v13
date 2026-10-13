<?php

use App\Enums\PermissionName;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyContextController;
use App\Http\Controllers\Api\MasterData\PropertyDevelopmentController;
use App\Http\Controllers\Api\Platform\PlatformIdentityController;
use App\Http\Resources\AuthUserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Property ERP API',
    ]);
});

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return AuthUserResource::make($request->user());
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/companies', [CompanyContextController::class, 'index']);
    Route::get('/company-context', [CompanyContextController::class, 'show'])
        ->middleware('company');

    // Platform authorization is independent from company membership/Spatie Teams.
    Route::prefix('platform')
        ->middleware('platform.admin')
        ->group(function () {
            Route::get('/me', [PlatformIdentityController::class, 'show']);
        });

    Route::prefix('master-data/property-developments')
        ->middleware('company')
        ->group(function () {
            Route::get('/', [PropertyDevelopmentController::class, 'index'])
                ->middleware('can:' . PermissionName::MasterDataPropertyDevelopmentsView->value);

            Route::post('/', [PropertyDevelopmentController::class, 'store'])
                ->middleware('can:' . PermissionName::MasterDataPropertyDevelopmentsCreate->value);
        });
});
