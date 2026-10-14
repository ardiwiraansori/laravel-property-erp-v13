<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\DiagnosticReport;
use App\Support\Diagnostics\DiagnosticFields;
use App\Support\Diagnostics\DiagnosticPerformance;
use App\Support\Diagnostics\DiagnosticWorkflow;
use App\Support\Platform\PlatformDirectoryAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Metadata-only support intake; no tenant business data or user impersonation. */
final class PlatformDiagnosticController extends Controller
{
    /** Active ticket age, measured since initial reporting (not an SLA). */
    private const OPEN_STATUSES = ['new', 'triaged', 'in_progress'];

    public function __construct(
        private readonly PlatformDirectoryAudit $audit,
        private readonly DiagnosticPerformance $performance,
    ) {
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
            'age' => ['sometimes', Rule::in(['under_24h', 'day_1_3', 'day_3_7', 'over_7d'])],
            'inactivity' => ['sometimes', Rule::in(['under_24h', 'day_1_3', 'day_3_7', 'over_7d'])],
            'sort' => ['sometimes', Rule::in(['newest', 'oldest', 'stale_first', 'recent_activity'])],
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

        // Bucket edges: <24h, [24h, 72h), [72h, 168h), >=168h.
        // Exact boundaries are assigned to the older bucket; future-dated
        // records are grouped into the newest bucket, not exposed separately.
        $now = now();
        $cutoff24 = $now->copy()->subHours(24);
        $cutoff72 = $now->copy()->subHours(72);
        $cutoff168 = $now->copy()->subHours(168);
        $aging = (clone $scope)->whereIn('status', self::OPEN_STATUSES)
            ->selectRaw(
                'COUNT(*) as total, '.
                'SUM(CASE WHEN created_at > ? THEN 1 ELSE 0 END) as under_24h, '.
                'SUM(CASE WHEN created_at > ? AND created_at <= ? THEN 1 ELSE 0 END) as day_1_3, '.
                'SUM(CASE WHEN created_at > ? AND created_at <= ? THEN 1 ELSE 0 END) as day_3_7, '.
                'SUM(CASE WHEN created_at <= ? THEN 1 ELSE 0 END) as over_7d',
                [$cutoff24, $cutoff72, $cutoff24, $cutoff168, $cutoff72, $cutoff168],
            )->first();

        // Inactivity is time since the report row was last modified, not time since
        // its creation. Both allowed workflow mutations update updated_at. For
        // legacy rows with no updated_at, fall back to creation timestamp.
        // This is not an SLA or evidence of any support communication.
        $lastActivity = 'COALESCE(updated_at, created_at)';
        $inactivity = (clone $scope)->whereIn('status', self::OPEN_STATUSES)
            ->selectRaw(
                'COUNT(*) as total, '.
                'SUM(CASE WHEN '.$lastActivity.' > ? THEN 1 ELSE 0 END) as under_24h, '.
                'SUM(CASE WHEN '.$lastActivity.' > ? AND '.$lastActivity.' <= ? THEN 1 ELSE 0 END) as day_1_3, '.
                'SUM(CASE WHEN '.$lastActivity.' > ? AND '.$lastActivity.' <= ? THEN 1 ELSE 0 END) as day_3_7, '.
                'SUM(CASE WHEN '.$lastActivity.' <= ? THEN 1 ELSE 0 END) as over_7d',
                [$cutoff24, $cutoff72, $cutoff24, $cutoff168, $cutoff72, $cutoff168],
            )->first();

        // Rolling-cohort workflow metrics are computed from existing append-only
        // status events, under the same base company/category/impact scope.
        $performance = $this->performance->summarize(clone $scope, $now);

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
            'status', 'assigned_platform_admin_user_id', 'workflow_version', 'created_at', 'updated_at',
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

        if (isset($params['age'])) {
            // An age lane always represents outstanding (not resolved/closed) reports.
            $query->whereIn('status', self::OPEN_STATUSES);
            switch ($params['age']) {
                case 'under_24h':
                    $query->where('created_at', '>', $cutoff24);
                    break;
                case 'day_1_3':
                    $query->where('created_at', '>', $cutoff72)->where('created_at', '<=', $cutoff24);
                    break;
                case 'day_3_7':
                    $query->where('created_at', '>', $cutoff168)->where('created_at', '<=', $cutoff72);
                    break;
                case 'over_7d':
                    $query->where('created_at', '<=', $cutoff168);
                    break;
            }
        }
        if (isset($params['inactivity'])) {
            // Inactivity always selects open reports and intersects with any
            // status, age, assignment, company, category and impact filters.
            $query->whereIn('status', self::OPEN_STATUSES);
            switch ($params['inactivity']) {
                case 'under_24h':
                    $query->whereRaw($lastActivity.' > ?', [$cutoff24]);
                    break;
                case 'day_1_3':
                    $query->whereRaw($lastActivity.' > ? AND '.$lastActivity.' <= ?', [$cutoff72, $cutoff24]);
                    break;
                case 'day_3_7':
                    $query->whereRaw($lastActivity.' > ? AND '.$lastActivity.' <= ?', [$cutoff168, $cutoff72]);
                    break;
                case 'over_7d':
                    $query->whereRaw($lastActivity.' <= ?', [$cutoff168]);
                    break;
            }
        }

        // Stable ID tie-breaker is mandatory for deterministic pagination.
        switch ($params['sort'] ?? 'newest') {
            case 'oldest':
                $query->orderBy('created_at')->orderBy('id');
                break;
            case 'stale_first':
                $query->orderByRaw($lastActivity.' ASC')->orderBy('id');
                break;
            case 'recent_activity':
                $query->orderByRaw($lastActivity.' DESC')->orderByDesc('id');
                break;
            default:
                $query->orderByDesc('created_at')->orderByDesc('id');
        }
        $page = $query->paginate((int) ($params['per_page'] ?? 20));
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
                'updated_at' => ($report->updated_at ?? $report->created_at)?->toIso8601String(),
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
            'open_aging' => [
                'total' => (int) ($aging?->total ?? 0),
                'under_24h' => (int) ($aging?->under_24h ?? 0),
                'day_1_3' => (int) ($aging?->day_1_3 ?? 0),
                'day_3_7' => (int) ($aging?->day_3_7 ?? 0),
                'over_7d' => (int) ($aging?->over_7d ?? 0),
            ],
            'open_inactivity' => [
                'total' => (int) ($inactivity?->total ?? 0),
                'under_24h' => (int) ($inactivity?->under_24h ?? 0),
                'day_1_3' => (int) ($inactivity?->day_1_3 ?? 0),
                'day_3_7' => (int) ($inactivity?->day_3_7 ?? 0),
                'over_7d' => (int) ($inactivity?->over_7d ?? 0),
            ],
            'workflow_performance' => $performance,
        ])->header('X-Request-ID', $requestId);
    }
}
