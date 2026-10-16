<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Models\PropertyDevelopment;
use App\Support\MasterData\PropertyDevelopmentDirectoryFilter;
use App\Support\Tenancy\CurrentCompanyContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Tenant-only, permissioned CSV export of the full filtered directory. */
final class PropertyDevelopmentCsvExportController extends Controller
{
    private const MAX_ROWS = 1000;

    public function __invoke(Request $request, CurrentCompanyContext $context): Response|\Illuminate\Http\JsonResponse
    {
        $params = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'active', 'inactive'])],
            'sort' => ['sometimes', Rule::in(PropertyDevelopmentDirectoryFilter::SORTS)],
            'created_from' => ['sometimes', 'date_format:Y-m-d'],
            'created_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            // Export covers all matching rows, never one arbitrary pagination window.
            'page' => ['prohibited'],
            'per_page' => ['prohibited'],
        ]);

        $query = PropertyDevelopmentDirectoryFilter::apply(
            PropertyDevelopment::query()->where('company_id', $context->id()),
            $params,
        );
        $rows = PropertyDevelopmentDirectoryFilter::order($query, $params['sort'] ?? 'name_asc')
            ->limit(self::MAX_ROWS + 1)
            ->get(['code', 'name', 'is_active', 'created_at']);

        // Refuse oversize exports; never silently omit matching records.
        if ($rows->count() > self::MAX_ROWS) {
            return response()->json([
                'message' => 'Too many matching developments. Narrow your filters (maximum 1000 records).',
            ], 422);
        }

        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            abort(500, 'Unable to prepare the CSV export.');
        }

        try {
            // UTF-8 BOM improves Excel compatibility on Windows.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Code', 'Name', 'Status', 'Created at (UTC)'], ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($handle, [
                    self::safeCell($row->code),
                    self::safeCell($row->name),
                    $row->is_active ? 'Active' : 'Inactive',
                    $row->created_at?->toISOString() ?? '',
                ], ',', '"', '');
            }
            rewind($handle);
            $csv = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if ($csv === false) {
            abort(500, 'Unable to prepare the CSV export.');
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="property-developments.csv"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function safeCell(string $value): string
    {
        // Replace control/newline/tab characters and neutralize spreadsheet formulas
        // even when they begin after spaces (including Unicode whitespace).
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        return preg_match('/^\s*[=+\-@]/u', $clean) === 1 ? "'".$clean : $clean;
    }
}
