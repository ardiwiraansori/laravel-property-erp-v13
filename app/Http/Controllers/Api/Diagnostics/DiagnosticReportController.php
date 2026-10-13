<?php

namespace App\Http\Controllers\Api\Diagnostics;

use App\Http\Controllers\Controller;
use App\Models\DiagnosticReport;
use App\Support\Diagnostics\DiagnosticFields;
use App\Support\Tenancy\CurrentCompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Tenant reporting: own incidents only, scoped by membership-resolved company. */
final class DiagnosticReportController extends Controller
{
    public function store(Request $request, CurrentCompanyContext $context): JsonResponse
    {
        $validated = $request->validate(DiagnosticFields::rules());
        $companyId = $context->id();
        $userId = (int) $request->user()->getAuthIdentifier();
        $publicId = (string) Str::ulid();

        // Explicit fields only. No text, raw telemetry, URL, referrer, user agent,
        // request payload, token, cookie, session content or stack trace is stored.
        DB::table('diagnostic_reports')->insert([
            'public_id' => $publicId,
            'company_id' => $companyId,
            'reporter_user_id' => $userId,
            'category' => $validated['category'],
            'module' => $validated['module'],
            'impact' => $validated['impact'],
            'error_kind' => $validated['error_kind'],
            'browser_family' => $validated['browser_family'],
            'viewport' => $validated['viewport'],
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => [
            'public_id' => $publicId,
            'category' => $validated['category'],
            'module' => $validated['module'],
            'status' => 'new',
        ]], 201);
    }

    public function index(Request $request, CurrentCompanyContext $context): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);
        $page = DiagnosticReport::query()
            ->where('company_id', $context->id())
            ->where('reporter_user_id', (int) $request->user()->getAuthIdentifier())
            ->orderByDesc('id')
            ->paginate((int) ($params['per_page'] ?? 10));

        return response()->json([
            'data' => $page->getCollection()->map(static fn (DiagnosticReport $item): array => [
                'public_id' => $item->public_id,
                'category' => $item->category,
                'module' => $item->module,
                'impact' => $item->impact,
                'status' => $item->status,
                'created_at' => $item->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
