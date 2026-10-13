<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\DiagnosticReport;
use App\Support\Diagnostics\DiagnosticWorkflow;
use App\Support\Platform\PlatformDirectoryAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Platform-only support operations; always reached through admin + MFA guards. */
final class PlatformDiagnosticWorkflowController extends Controller
{
    public function __construct(
        private readonly DiagnosticWorkflow $workflow,
        private readonly PlatformDirectoryAudit $audit,
    ) {
    }

    public function assignees(Request $request): JsonResponse
    {
        $users = DB::table('platform_admins as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.is_active', true)->whereNull('p.revoked_at')
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('company_user')
                    ->whereColumn('company_user.user_id', 'p.user_id');
            })->orderBy('u.name')->orderBy('u.id')
            ->get(['u.id', 'u.name']);
        $requestId = $this->audit->record($request, 'platform.diagnostics.assignees.read');
        return response()->json(['data' => $users])->header('X-Request-ID', $requestId);
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $report = DiagnosticReport::query()->where('public_id', $publicId)->firstOrFail();
        $page = DB::table('diagnostic_report_events')
            ->where('diagnostic_report_id', $report->id)
            ->orderByDesc('id')->paginate(20);
        $requestId = $this->audit->record($request, 'platform.diagnostics.detail.read');
        return response()->json([
            'data' => [
                'public_id' => $report->public_id,
                'company_id' => $report->company_id,
                'reporter_user_id' => $report->reporter_user_id,
                'category' => $report->category,
                'module' => $report->module,
                'impact' => $report->impact,
                'status' => $report->status,
                'assigned_platform_admin_user_id' => $report->assigned_platform_admin_user_id,
                'workflow_version' => (int) $report->workflow_version,
            ],
            'history' => $page->getCollection()->map(static fn ($event): array => [
                'action' => $event->action,
                'actor_user_id' => $event->actor_user_id,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'from_assignee_user_id' => $event->from_assignee_user_id,
                'to_assignee_user_id' => $event->to_assignee_user_id,
                'created_at' => $event->created_at,
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ])->header('X-Request-ID', $requestId);
    }

    public function status(Request $request, string $publicId): JsonResponse
    {
        $input = $request->validate([
            'status' => ['required', Rule::in(DiagnosticWorkflow::STATUSES)],
            'workflow_version' => ['required', 'integer', 'min:0'],
        ]);
        $report = $this->workflow->changeStatus(
            $request, $publicId, $input['status'], (int) $input['workflow_version'],
        );
        return response()->json(['data' => $this->receipt($report)]);
    }

    public function assignment(Request $request, string $publicId): JsonResponse
    {
        $input = $request->validate([
            'assignee_user_id' => ['present', 'nullable', 'integer', 'min:1'],
            'workflow_version' => ['required', 'integer', 'min:0'],
        ]);
        $report = $this->workflow->changeAssignee(
            $request, $publicId, isset($input['assignee_user_id']) ? (int) $input['assignee_user_id'] : null,
            (int) $input['workflow_version'],
        );
        return response()->json(['data' => $this->receipt($report)]);
    }

    private function receipt(DiagnosticReport $report): array
    {
        return [
            'public_id' => $report->public_id,
            'status' => $report->status,
            'assigned_platform_admin_user_id' => $report->assigned_platform_admin_user_id,
            'workflow_version' => (int) $report->workflow_version,
        ];
    }
}
