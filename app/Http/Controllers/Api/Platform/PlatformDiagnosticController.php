<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\DiagnosticReport;
use App\Support\Diagnostics\DiagnosticFields;
use App\Support\Diagnostics\DiagnosticWorkflow;
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
            'impact' => ['sometimes', Rule::in(DiagnosticFields::IMPACTS)],
            'status' => ['sometimes', Rule::in(DiagnosticWorkflow::STATUSES)],
            'assignment' => ['sometimes', Rule::in(['all', 'mine', 'assigned', 'unassigned'])],
        ]);

        // The overview intentionally ignores status and assignment filters so the
        // operator can compare the whole filtered workload while viewing one lane.
        // All other filters are still respected, and no business tables are read.
        $scope = DiagnosticReport::query();
        if (isset($params['company_id'])) {
            $scope->where('company_id', (int) $params['company_id']);
        }
        if (isset($params['category'])) {
            $scope->where('category', $params['category']);
        }
        if (isset($params['impact'])) {
            $scope->where('impact', $params['impact']);
        }

        $statusCounts = array_fill_keys(DiagnosticWorkflow::STATUSES, 0);
        foreach ((clone $scope)->select('status')->selectRaw('COUNT(*) as report_count')
            ->groupBy('status')->get() as $row) {
            if (array_key_exists($row->status, $statusCounts)) {
                $statusCounts[$row->status] = (int) $row->report_count;
            }
        }

        $actorId = (int) $request->user()->getAuthIdentifier();
        $workload = (clone $scope)->selectRaw(
            'COUNT(*) as total, '.
            'SUM(CASE WHEN assigned_platform_admin_user_id IS NULL THEN 1 ELSE 0 END) as unassigned, '.
            'SUM(CASE WHEN assigned_platform_admin_user_id = ? THEN 1 ELSE 0 END) as mine',
            [$actorId],
        )->first();
        $total = (int) ($workload?->total ?? 0);
        $unassigned = (int) ($workload?->unassigned ?? 0);

        $query = (clone $scope)->select([
            'id', 'public_id', 'company_id', 'reporter_user_id', 'category',
            'module', 'impact', 'error_kind', 'browser_family', 'viewport',
            'status', 'assigned_platform_admin_user_id', 'workflow_version', 'created_at',
        ])->with('company:id,code');
        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }
        switch ($params['assignment'] ?? 'all') {
            case 'mine':
                $query->where('assigned_platform_admin_user_id', $actorId);
                break;
            case 'assigned':
                $query->whereNotNull('assigned_platform_admin_user_id');
                break;
            case 'unassigned':
                $query->whereNull('assigned_platform_admin_user_id');
                break;
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
                'assigned_platform_admin_user_id' => $report->assigned_platform_admin_user_id,
                'workflow_version' => (int) $report->workflow_version,
                'created_at' => $report->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'summary' => [
                'total' => $total,
                'by_status' => $statusCounts,
                'unassigned' => $unassigned,
                'assigned' => $total - $unassigned,
                'mine' => (int) ($workload?->mine ?? 0),
            ],
        ])->header('X-Request-ID', $requestId);
    }
}
