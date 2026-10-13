<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\Platform\PlatformDirectoryAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformCompanyController extends Controller
{
    public function __construct(private readonly PlatformDirectoryAudit $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Company::query()
            ->select(['id', 'code', 'name', 'is_active', 'created_at'])
            ->withCount(['users as members_count']);

        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            // Escape LIKE wildcards so a search term is treated literally.
            $escaped = addcslashes($search, '%_\\');
            $query->where(static function ($builder) use ($escaped): void {
                $builder->where('code', 'like', '%'.$escaped.'%')
                    ->orWhere('name', 'like', '%'.$escaped.'%');
            });
        }

        $page = $query->orderBy('name')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        $requestId = $this->audit->record($request, 'platform.companies.read');

        return response()->json([
            'data' => $page->getCollection()->map(static fn (Company $company): array => [
                'id' => $company->id,
                'code' => $company->code,
                'name' => $company->name,
                'is_active' => $company->is_active,
                'members_count' => (int) $company->members_count,
                'created_at' => $company->created_at?->toIso8601String(),
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
