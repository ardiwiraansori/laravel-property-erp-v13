<?php

use App\Enums\PermissionName;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyContextController;
use App\Http\Controllers\Api\MasterData\PropertyDevelopmentController;
use App\Http\Controllers\Api\Platform\PlatformCapabilityController;
use App\Http\Controllers\Api\Platform\PlatformCompanyController;
use App\Http\Controllers\Api\Platform\PlatformCompanyManagementController;
use App\Http\Controllers\Api\Platform\PlatformIdentityController;
use App\Http\Controllers\Api\Platform\PlatformUserController;
use App\Http\Controllers\Api\Platform\PlatformUserManagementController;
use App\Http\Resources\AuthUserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Property ERP API',
    ]);
});

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:erp-login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return AuthUserResource::make($request->user());
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/companies', [CompanyContextController::class, 'index']);
    Route::get('/company-context', [CompanyContextController::class, 'show'])
        ->middleware('company');

    // Platform administration never resolves a tenant or uses tenant permissions.
    Route::prefix('platform')
        ->middleware('platform.admin')
        ->group(function () {
            Route::get('/me', [PlatformIdentityController::class, 'show']);
            Route::get('/capabilities', [PlatformCapabilityController::class, 'show']);
            Route::get('/companies', [PlatformCompanyController::class, 'index']);
            Route::get('/users', [PlatformUserController::class, 'index']);
            Route::get('/users/{user}/memberships', [PlatformUserController::class, 'memberships'])
                ->whereNumber('user');

            // Write paths are intentionally locked until a separately reviewed
            // server-issued platform MFA verification has been implemented.
            Route::middleware('platform.write')->group(function () {
                Route::post('/companies', [PlatformCompanyManagementController::class, 'store']);
                Route::patch('/companies/{company}', [PlatformCompanyManagementController::class, 'update'])
                    ->whereNumber('company');
                Route::patch('/users/{user}', [PlatformUserManagementController::class, 'update'])
                    ->whereNumber('user');
                Route::post('/users/{user}/memberships/{company}', [PlatformUserManagementController::class, 'attach'])
                    ->whereNumber('user')->whereNumber('company');
                Route::delete('/users/{user}/memberships/{company}', [PlatformUserManagementController::class, 'detach'])
                    ->whereNumber('user')->whereNumber('company');
            });
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
