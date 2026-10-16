<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StorePropertyDevelopmentRequest;
use App\Http\Requests\MasterData\UpdatePropertyDevelopmentRequest;
use App\Http\Resources\MasterData\PropertyDevelopmentDetailResource;
use App\Http\Resources\MasterData\PropertyDevelopmentResource;
use App\Models\PropertyDevelopment;
use App\Support\MasterData\PropertyDevelopmentDirectoryFilter;
use App\Support\Tenancy\CurrentCompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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

        PropertyDevelopmentDirectoryFilter::apply($developments, $params);

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

    /** A record lookup must always be constrained by the server-resolved tenant. */
    public function show(CurrentCompanyContext $context, int $development): JsonResponse
    {
        $record = PropertyDevelopment::query()
            ->where('company_id', $context->id())
            ->whereKey($development)
            ->firstOrFail();

        return PropertyDevelopmentDetailResource::make($record)->response();
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
    /** Company-resolved update; never accepts a caller-selected tenant or mutable code. */
    public function update(
        UpdatePropertyDevelopmentRequest $request,
        CurrentCompanyContext $context,
        int $development,
    ): JsonResponse {
        $data = $request->validated();

        $updated = DB::transaction(function () use ($context, $development, $data): PropertyDevelopment {
            $model = PropertyDevelopment::query()
                ->where('company_id', $context->id())
                ->whereKey($development)
                ->lockForUpdate()
                ->firstOrFail();

            // Snapshot CAS: protects against another editor changing name or status.
            // Neither id, company_id nor code can be updated via this endpoint.
            if ($model->name !== $data['expected_name'] ||
                $model->is_active !== (bool) $data['expected_is_active']) {
                abort(409, 'The development changed. Reload the directory before editing again.');
            }

            if ($model->name !== $data['name'] || $model->is_active !== (bool) $data['is_active']) {
                $model->update([
                    'name' => $data['name'],
                    'is_active' => (bool) $data['is_active'],
                ]);
            }

            return $model->refresh();
        });

        return PropertyDevelopmentResource::make($updated)->response();
    }

}
