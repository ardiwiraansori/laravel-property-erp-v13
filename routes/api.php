<?php

use App\Enums\PermissionName;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyContextController;
use App\Http\Controllers\Api\Diagnostics\DiagnosticReportController;
use App\Http\Controllers\Api\MasterData\PropertyDevelopmentController;
use App\Http\Controllers\Api\MasterData\PropertyDevelopmentCsvExportController;
use App\Http\Controllers\Api\MasterData\PropertyDevelopmentSummaryController;
use App\Http\Controllers\Api\Platform\PlatformCapabilityController;
use App\Http\Controllers\Api\Platform\PlatformCompanyController;
use App\Http\Controllers\Api\Platform\PlatformCompanyManagementController;
use App\Http\Controllers\Api\Platform\PlatformDiagnosticController;
use App\Http\Controllers\Api\Platform\PlatformDiagnosticWorkflowController;
use App\Http\Controllers\Api\Platform\PlatformIdentityController;
use App\Http\Controllers\Api\Platform\PlatformMfaController;
use App\Http\Controllers\Api\Platform\PlatformUserController;
use App\Http\Controllers\Api\Platform\PlatformUserManagementController;
use App\Http\Resources\AuthUserResource;
use App\Support\MasterData\PropertyDevelopmentAccess;
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
                Route::get('/diagnostics/assignees', [PlatformDiagnosticWorkflowController::class, 'assignees']);
                Route::get('/diagnostics/reports/{publicId}', [PlatformDiagnosticWorkflowController::class, 'show'])
                    ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}');
                Route::get('/users', [PlatformUserController::class, 'index']);
                Route::get('/users/{user}/memberships', [PlatformUserController::class, 'memberships'])
                    ->whereNumber('user');

                Route::middleware('platform.write')->group(function () {
                    Route::patch('/diagnostics/reports/{publicId}/status', [PlatformDiagnosticWorkflowController::class, 'status'])
                        ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:platform-diagnostics-update');
                    Route::patch('/diagnostics/reports/{publicId}/assignment', [PlatformDiagnosticWorkflowController::class, 'assignment'])
                        ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:platform-diagnostics-update');
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
        Route::get('/{publicId}', [DiagnosticReportController::class, 'show'])
            ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}');
        Route::post('/', [DiagnosticReportController::class, 'store'])
            ->middleware('throttle:diagnostics-report');
    });

    Route::prefix('master-data/property-developments')
        ->middleware('company')
        ->group(function () {
            Route::get('/', [PropertyDevelopmentController::class, 'index'])
                ->middleware('can:' . PermissionName::MasterDataPropertyDevelopmentsView->value);

            // Fixed export path precedes the numeric detail route and is read-only.
            Route::get('/export', PropertyDevelopmentCsvExportController::class)
                ->middleware('can:' . PermissionName::MasterDataPropertyDevelopmentsView->value);

            // Aggregated counts for the resolved active company only; uses the existing view permission.
            Route::get('/summary', [PropertyDevelopmentSummaryController::class, 'show'])
                ->middleware('can:' . PermissionName::MasterDataPropertyDevelopmentsView->value);

            // Place the fixed /summary path before the numeric detail path.
            // Both reads require the existing tenant-scoped view permission.
            Route::get('/{development}', [PropertyDevelopmentController::class, 'show'])
                ->whereNumber('development')
                ->middleware('can:' . PermissionName::MasterDataPropertyDevelopmentsView->value);

            Route::post('/', [PropertyDevelopmentController::class, 'store'])
                ->middleware('can:' . PermissionName::MasterDataPropertyDevelopmentsCreate->value);

            Route::patch('/{development}', [PropertyDevelopmentController::class, 'update'])
                ->whereNumber('development')
                ->middleware('can:' . PropertyDevelopmentAccess::UPDATE);
        });
});
