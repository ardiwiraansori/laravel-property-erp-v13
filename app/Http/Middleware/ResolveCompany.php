<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentCompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->header('X-Company-ID');

        if (! is_string($companyId) || ! preg_match('/^[1-9][0-9]{0,18}$/D', $companyId)) {
            return response()->json([
                'message' => 'A valid X-Company-ID header is required.',
            ], 422);
        }

        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $company = $user->companies()
            ->whereKey((int) $companyId)
            ->where('companies.is_active', true)
            ->first();

        if ($company === null) {
            return response()->json(['message' => 'Company access denied.'], 403);
        }

        // The tenant was validated by membership and active status above.
        setPermissionsTeamId((int) $company->getKey());
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $request->attributes->set(CurrentCompanyContext::ATTRIBUTE, $company);

        return $next($request);
    }
}
