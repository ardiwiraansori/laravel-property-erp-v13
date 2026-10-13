<?php

use App\Enums\PermissionName;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyContextController;
use App\Http\Controllers\Api\Diagnostics\DiagnosticReportController;
use App\Http\Controllers\Api\MasterData\PropertyDevelopmentController;
use App\Http\Controllers\Api\Platform\PlatformCapabilityController;
use App\Http\Controllers\Api\Platform\PlatformCompanyController;
use App\Http\Controllers\Api\Platform\PlatformCompanyManagementController;
use App\Http\Controllers\Api\Platform\PlatformDiagnosticController;
use App\Http\Controllers\Api\Platform\PlatformIdentityController;
use App\Http\Controllers\Api\Platform\PlatformMfaController;
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
            Route::prefix('mfa')->middleware('platform.session')->group(function () {
                Route::get('/status', [PlatformMfaController::class, 'status']);
                Route::middleware('throttle:platform-mfa-attempt')->group(function () {
                Route::post('/setup', [PlatformMfaController::class, 'setup']);
                Route::post('/confirm', [PlatformMfaController::class, 'confirm']);
                Route::post('/challenge', [PlatformMfaController::class, 'challenge']);
                Route::post('/recover', [PlatformMfaController::class, 'recover']);
                });
            });

            Route::middleware('platform.mfa')->group(function () {
                Route::get('/me', [PlatformIdentityController::class, 'show']);
                Route::get('/capabilities', [PlatformCapabilityController::class, 'show']);
                Route::get('/companies', [PlatformCompanyController::class, 'index']);
                Route::get('/diagnostics/reports', [PlatformDiagnosticController::class, 'index']);
                Route::get('/users', [PlatformUserController::class, 'index']);
                Route::get('/users/{user}/memberships', [PlatformUserController::class, 'memberships'])
                    ->whereNumber('user');

                Route::middleware('platform.write')->group(function () {
                    Route::post('/companies', [PlatformCompanyManagementController::class, 'store']);
                    Route::patch('/companies/{company}', [PlatformCompanyManagementController::class, 'update'])->whereNumber('company');
                    Route::patch('/users/{user}', [PlatformUserManagementController::class, 'update'])->whereNumber('user');
                    Route::post('/users/{user}/memberships/{company}', [PlatformUserManagementController::class, 'attach'])
                        ->whereNumber('user')->whereNumber('company');
                    Route::delete('/users/{user}/memberships/{company}', [PlatformUserManagementController::class, 'detach'])
                        ->whereNumber('user')->whereNumber('company');
                });
            });
        });

    // Tenant-scoped incident intake. POST is rate-limited per reporter and company.
    Route::prefix('diagnostics/reports')->middleware('company')->group(function () {
        Route::get('/', [DiagnosticReportController::class, 'index']);
        Route::post('/', [DiagnosticReportController::class, 'store'])
            ->middleware('throttle:diagnostics-report');
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
