<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StorePropertyDevelopmentRequest;
use App\Http\Resources\MasterData\PropertyDevelopmentResource;
use App\Models\PropertyDevelopment;
use App\Support\Tenancy\CurrentCompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PropertyDevelopmentController extends Controller
{
    public function index(Request $request, CurrentCompanyContext $context): AnonymousResourceCollection|JsonResponse
    {
        $params = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'active', 'inactive'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        // Retain the original unpaginated GET contract for existing integrations.
        // The enhanced, bounded directory contract is opt-in via query parameters.
        $developments = PropertyDevelopment::query()
            ->where('company_id', $context->id());

        if (! $request->hasAny(['search', 'status', 'page', 'per_page'])) {
            return PropertyDevelopmentResource::collection(
                $developments->orderBy('name')->orderBy('id')->get()
            );
        }

        $search = trim((string) ($params['search'] ?? ''));
        if ($search !== '') {
            // Match input literally: SQL wildcard symbols must not broaden the search.
            // ESCAPE '!' is supported by SQLite (tests) and MariaDB (development).
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
            $pattern = '%'.$escaped.'%';
            $developments->where(static function (Builder $query) use ($pattern): void {
                $query->whereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        if (($params['status'] ?? 'all') !== 'all') {
            $developments->where('is_active', $params['status'] === 'active');
        }

        $page = $developments->orderBy('name')->orderBy('id')
            ->paginate((int) ($params['per_page'] ?? 10), ['*'], 'page', (int) ($params['page'] ?? 1));

        return response()->json([
            'data' => PropertyDevelopmentResource::collection($page->items())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(
        StorePropertyDevelopmentRequest $request,
        CurrentCompanyContext $context,
    ): JsonResponse {
        $development = PropertyDevelopment::query()->create([
            ...$request->safe()->only(['code', 'name']),
            'company_id' => $context->id(),
        ]);

        return PropertyDevelopmentResource::make($development)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
