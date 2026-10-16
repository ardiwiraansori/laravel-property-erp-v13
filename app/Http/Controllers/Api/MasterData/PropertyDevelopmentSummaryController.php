<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Models\PropertyDevelopment;
use App\Support\Tenancy\CurrentCompanyContext;
use Illuminate\Http\JsonResponse;

/** Read-only, tenant-scoped property development portfolio totals. */
final class PropertyDevelopmentSummaryController extends Controller
{
    public function show(CurrentCompanyContext $context): JsonResponse
    {
        // Aggregate inside the resolved tenant. Never accept a caller-supplied company ID.
        // CASE/COUNT works in both MariaDB and the existing SQLite in-memory tests.
        $counts = PropertyDevelopment::query()
            ->where('company_id', $context->id())
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active')
            ->first();

        $total = (int) ($counts?->total ?? 0);
        $active = (int) ($counts?->active ?? 0);

        return response()->json([
            'data' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
            ],
        ]);
    }
}
