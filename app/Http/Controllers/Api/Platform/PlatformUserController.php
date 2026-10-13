<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Support\Platform\PlatformDirectoryAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformUserController extends Controller
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

        $query = User::query()
            ->select(['id', 'name', 'email'])
            ->withCount(['companies as memberships_count']);

        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $query->where(static function ($builder) use ($escaped): void {
                $builder->where('name', 'like', '%'.$escaped.'%')
                    ->orWhere('email', 'like', '%'.$escaped.'%');
            });
        }

        $page = $query->orderBy('name')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        $requestId = $this->audit->record($request, 'platform.users.read');

        return response()->json([
            'data' => $page->getCollection()->map(static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'memberships_count' => (int) $user->memberships_count,
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ])->header('X-Request-ID', $requestId);
    }

    public function memberships(Request $request, User $user): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $page = $user->companies()
            ->select(['companies.id', 'companies.code', 'companies.name', 'companies.is_active'])
            ->orderBy('companies.name')->orderBy('companies.id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        $requestId = $this->audit->record($request, 'platform.user_memberships.read', $user);

        return response()->json([
            'data' => $page->getCollection()->map(static fn (Company $company): array => [
                'id' => $company->id,
                'code' => $company->code,
                'name' => $company->name,
                'is_active' => $company->is_active,
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
