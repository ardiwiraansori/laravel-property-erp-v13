<?php

namespace App\Support\Diagnostics;

use Illuminate\Validation\Rule;

/** Strict allowlists prevent arbitrary event bodies and business data ingestion. */
final class DiagnosticFields
{
    public const CATEGORIES = [
        'page_error', 'unexpected_behavior', 'access_problem', 'slow_response', 'other',
    ];

    public const MODULES = [
        'dashboard', 'master_data', 'crm', 'sales', 'accounting', 'reports', 'navigation', 'other',
    ];

    public const IMPACTS = ['blocked', 'partial', 'minor', 'unknown'];
    public const ERROR_KINDS = ['none', 'load_failed', 'permission_denied', 'timeout', 'unexpected_result', 'unknown'];
    public const BROWSERS = ['chrome', 'edge', 'firefox', 'safari', 'other', 'unknown'];
    public const VIEWPORTS = ['mobile', 'tablet', 'desktop', 'unknown'];

    public static function rules(): array
    {
        return [
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'module' => ['required', Rule::in(self::MODULES)],
            'impact' => ['required', Rule::in(self::IMPACTS)],
            'error_kind' => ['required', Rule::in(self::ERROR_KINDS)],
            'browser_family' => ['required', Rule::in(self::BROWSERS)],
            'viewport' => ['required', Rule::in(self::VIEWPORTS)],
        ];
    }
}
