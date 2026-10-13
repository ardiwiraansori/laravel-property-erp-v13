<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\DiagnosticReport;
use App\Support\Diagnostics\DiagnosticFields;
use App\Support\Platform\PlatformDirectoryAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Metadata-only support intake; no tenant business data or user impersonation. */
final class PlatformDiagnosticController extends Controller
{
    public function __construct(private readonly PlatformDirectoryAudit $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'company_id' => ['sometimes', 'integer', 'min:1', 'exists:companies,id'],
            'category' => ['sometimes', Rule::in(DiagnosticFields::CATEGORIES)],
            'status' => ['sometimes', Rule::in(['new', 'triaged', 'resolved', 'closed'])],
        ]);

        $query = DiagnosticReport::query()
            ->select([
                'id', 'public_id', 'company_id', 'reporter_user_id', 'category',
                'module', 'impact', 'error_kind', 'browser_family', 'viewport',
                'status', 'created_at',
            ])->with('company:id,code');
        if (isset($params['company_id'])) {
            $query->where('company_id', (int) $params['company_id']);
        }
        if (isset($params['category'])) {
            $query->where('category', $params['category']);
        }
        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }
        $page = $query->orderByDesc('id')->paginate((int) ($params['per_page'] ?? 20));
        $requestId = $this->audit->record($request, 'platform.diagnostics.read');

        return response()->json([
            'data' => $page->getCollection()->map(static fn (DiagnosticReport $report): array => [
                'public_id' => $report->public_id,
                'company_id' => $report->company_id,
                'company_code' => $report->company?->code,
                'reporter_user_id' => $report->reporter_user_id,
                'category' => $report->category,
                'module' => $report->module,
                'impact' => $report->impact,
                'error_kind' => $report->error_kind,
                'browser_family' => $report->browser_family,
                'viewport' => $report->viewport,
                'status' => $report->status,
                'created_at' => $report->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ])->header('X-Request-ID', $requestId);
    }
}
