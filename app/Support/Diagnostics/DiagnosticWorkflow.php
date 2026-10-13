<?php

namespace App\Support\Diagnostics;

use App\Models\DiagnosticReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Server-authoritative, allowlisted support workflow; no notes or raw evidence. */
final class DiagnosticWorkflow
{
    public const STATUSES = ['new', 'triaged', 'in_progress', 'resolved', 'closed'];

    private const NEXT = [
        'new' => ['triaged'],
        'triaged' => ['in_progress'],
        'in_progress' => ['resolved', 'triaged'],
        'resolved' => ['closed', 'in_progress'],
        'closed' => ['triaged'],
    ];

    public static function allowedNext(string $status): array
    {
        return self::NEXT[$status] ?? [];
    }

    public function changeStatus(Request $request, string $publicId, string $next, int $version): DiagnosticReport
    {
        if (! in_array($next, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown diagnostic state.');
        }

        return DB::transaction(function () use ($request, $publicId, $next, $version): DiagnosticReport {
            $report = DiagnosticReport::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->requireVersion($report, $version);
            if (! in_array($next, self::allowedNext($report->status), true)) {
                throw ValidationException::withMessages(['status' => 'This status transition is not permitted.']);
            }
            $previous = $report->status;
            DB::table('diagnostic_reports')->where('id', $report->id)->update([
                'status' => $next,
                'workflow_version' => $version + 1,
                'updated_at' => now(),
            ]);
            $this->event($request, $report, 'status_changed', $previous, $next,
                $report->assigned_platform_admin_user_id, $report->assigned_platform_admin_user_id);
            $this->platformAudit($request, $report, 'platform.diagnostics.status.change');
            return $report->fresh();
        });
    }

    public function changeAssignee(Request $request, string $publicId, ?int $userId, int $version): DiagnosticReport
    {
        return DB::transaction(function () use ($request, $publicId, $userId, $version): DiagnosticReport {
            $report = DiagnosticReport::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->requireVersion($report, $version);
            if ($userId !== null && ! $this->eligibleAssignee($userId)) {
                throw ValidationException::withMessages(['assignee_user_id' => 'Only an active, tenant-free platform administrator is eligible.']);
            }
            $previous = $report->assigned_platform_admin_user_id === null
                ? null : (int) $report->assigned_platform_admin_user_id;
            if ($previous === $userId) {
                throw ValidationException::withMessages(['assignee_user_id' => 'Assignee has not changed.']);
            }
            DB::table('diagnostic_reports')->where('id', $report->id)->update([
                'assigned_platform_admin_user_id' => $userId,
                'workflow_version' => $version + 1,
                'updated_at' => now(),
            ]);
            $this->event($request, $report, 'assignment_changed', $report->status, $report->status, $previous, $userId);
            $this->platformAudit($request, $report, 'platform.diagnostics.assignment.change');
            return $report->fresh();
        });
    }

    public function eligibleAssignee(int $id): bool
    {
        return DB::table('platform_admins as p')
            ->where('p.user_id', $id)
            ->where('p.is_active', true)
            ->whereNull('p.revoked_at')
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('company_user')
                    ->whereColumn('company_user.user_id', 'p.user_id');
            })->exists();
    }

    private function requireVersion(DiagnosticReport $report, int $version): void
    {
        if ((int) $report->workflow_version !== $version) {
            abort(409, 'The diagnostic report changed. Refresh before updating it.');
        }
    }

    private function event(Request $request, DiagnosticReport $report, string $action,
        ?string $from, ?string $to, ?int $previousUser, ?int $nextUser): void
    {
        DB::table('diagnostic_report_events')->insert([
            'diagnostic_report_id' => $report->id,
            'company_id' => $report->company_id,
            'actor_user_id' => (int) $request->user()->getAuthIdentifier(),
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'from_assignee_user_id' => $previousUser,
            'to_assignee_user_id' => $nextUser,
            'created_at' => now(),
        ]);
    }

    /** Call inside the same DB transaction as the incident update and history. */
    private function platformAudit(Request $request, DiagnosticReport $report, string $action): void
    {
        DB::table('platform_audit_logs')->insert([
            'event_id' => (string) Str::ulid(),
            'request_id' => (string) Str::ulid(),
            'actor_user_id' => (int) $request->user()->getAuthIdentifier(),
            'action' => $action,
            'target_type' => 'diagnostic_report',
            'target_id' => $report->id,
            'company_id' => $report->company_id,
            'outcome' => 'success',
            'reason' => null,
            'metadata' => null,
            'created_at' => now(),
        ]);
    }
}
