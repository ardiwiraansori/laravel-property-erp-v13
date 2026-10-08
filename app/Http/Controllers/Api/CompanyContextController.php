<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentCompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanyContextController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companies = $request->user()->companies()
            ->where('companies.is_active', true)
            ->orderBy('companies.name')
            ->get(['companies.id', 'companies.code', 'companies.name']);

        return response()->json(['data' => $companies]);
    }

    public function show(Request $request, CurrentCompanyContext $context): JsonResponse
    {
        $company = $context->company();
        $user = $request->user();

        // ResolveCompany middleware has verified membership and established
        // the Spatie team context before this controller is invoked.
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return response()->json([
            'data' => [
                'id' => $company->id,
                'code' => $company->code,
                'name' => $company->name,
                'roles' => $user->getRoleNames()->sort()->values()->all(),
                'permissions' => $user->getAllPermissions()
                    ->pluck('name')->sort()->values()->all(),
            ],
        ]);
    }
}
