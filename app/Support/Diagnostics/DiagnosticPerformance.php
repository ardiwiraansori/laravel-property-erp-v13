<?php

namespace App\Support\Diagnostics;

use App\Support\Diagnostics\DiagnosticFields;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only 30-day reported cohort, derived exclusively from diagnostic event metadata. */
final class DiagnosticPerformance
{
    private const WINDOW_DAYS = 30;

    /**
     * The passed base scope is already restricted to company/category/impact.
     * Do not apply queue lane, status, pagination or assignment filters here.
     *
     * @return array<string, mixed>
     */
    public function summarize(Builder $baseScope, CarbonInterface $asOf): array
    {
        $reported = 0;
        $triaged = 0;
        $resolved = 0;
        $reopened = 0;
        $triageSeconds = 0;
        $resolutionSeconds = 0;

        // Fixed allowlist dimensions, never keys supplied by request parameters.
        $byModule = array_fill_keys(DiagnosticFields::MODULES, 0);
        $byCategory = array_fill_keys(DiagnosticFields::CATEGORIES, 0);
        $byImpact = array_fill_keys(DiagnosticFields::IMPACTS, 0);
        $dailyCounts = [];
        $dailyStart = $asOf->copy()->startOfDay()->subDays(6);
        for ($day = 0; $day < 7; $day++) {
            $dailyCounts[$dailyStart->copy()->addDays($day)->toDateString()] = 0;
        }

        (clone $baseScope)
            ->where('created_at', '>=', $asOf->copy()->subDays(self::WINDOW_DAYS))
            ->where('created_at', '<=', $asOf)
            ->select(['id', 'created_at', 'module', 'category', 'impact'])
            ->chunkById(200, function ($reports) use (
                &$reported, &$triaged, &$resolved, &$reopened, &$triageSeconds, &$resolutionSeconds,
                &$byModule, &$byCategory, &$byImpact, &$dailyCounts, $dailyStart
            ): void {
                $createdAt = [];
                foreach ($reports as $report) {
                    $createdAt[(int) $report->id] = $report->created_at->getTimestamp();
                    if (array_key_exists($report->module, $byModule)) {
                        $byModule[$report->module]++;
                    }
                    if (array_key_exists($report->category, $byCategory)) {
                        $byCategory[$report->category]++;
                    }
                    if (array_key_exists($report->impact, $byImpact)) {
                        $byImpact[$report->impact]++;
                    }
                    if ($report->created_at->greaterThanOrEqualTo($dailyStart)) {
                        $date = $report->created_at->toDateString();
                        if (array_key_exists($date, $dailyCounts)) {
                            $dailyCounts[$date]++;
                        }
                    }
                }
                $reported += count($createdAt);
                $firstTriaged = [];
                $firstResolved = [];
                $reopenedIds = [];

                $events = DB::table('diagnostic_report_events')
                    ->whereIn('diagnostic_report_id', array_keys($createdAt))
                    ->where('action', 'status_changed')
                    ->orderBy('created_at')->orderBy('id')
                    ->get(['diagnostic_report_id', 'from_status', 'to_status', 'created_at']);

                foreach ($events as $event) {
                    $id = (int) $event->diagnostic_report_id;
                    $eventSeconds = strtotime((string) $event->created_at);
                    // Protect the metric from malformed chronology, never write to fix it.
                    if ($eventSeconds === false || $eventSeconds < $createdAt[$id]) {
                        continue;
                    }
                    if ($event->from_status === 'new' && $event->to_status === 'triaged' && ! isset($firstTriaged[$id])) {
                        $firstTriaged[$id] = $eventSeconds - $createdAt[$id];
                    }
                    if ($event->to_status === 'resolved' && ! isset($firstResolved[$id])) {
                        $firstResolved[$id] = $eventSeconds - $createdAt[$id];
                    }
                    if (($event->from_status === 'resolved' && $event->to_status === 'in_progress') ||
                        ($event->from_status === 'closed' && $event->to_status === 'triaged')) {
                        $reopenedIds[$id] = true;
                    }
                }

                $triaged += count($firstTriaged);
                $resolved += count($firstResolved);
                $reopened += count($reopenedIds);
                $triageSeconds += array_sum($firstTriaged);
                $resolutionSeconds += array_sum($firstResolved);
            });

        $dailyReported = [];
        foreach ($dailyCounts as $date => $count) {
            $dailyReported[] = ['date' => $date, 'count' => $count];
        }

        return [
            'window_days' => self::WINDOW_DAYS,
            'reported' => $reported,
            'triaged' => $triaged,
            'resolved' => $resolved,
            'reopened' => $reopened,
            'awaiting_first_triage' => $reported - $triaged,
            'average_hours_to_first_triage' => $triaged > 0 ? round($triageSeconds / $triaged / 3600, 2) : null,
            'average_hours_to_first_resolution' => $resolved > 0 ? round($resolutionSeconds / $resolved / 3600, 2) : null,
            'case_mix' => [
                'by_module' => $byModule,
                'by_category' => $byCategory,
                'by_impact' => $byImpact,
            ],
            'daily_reported' => $dailyReported,
        ];
    }
}
