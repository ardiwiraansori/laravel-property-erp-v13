<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StorePropertyDevelopmentRequest;
use App\Http\Resources\MasterData\PropertyDevelopmentResource;
use App\Models\PropertyDevelopment;
use App\Support\Tenancy\CurrentCompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class PropertyDevelopmentController extends Controller
{
    public function index(CurrentCompanyContext $context): AnonymousResourceCollection
    {
        $developments = PropertyDevelopment::query()
            ->where('company_id', $context->id())
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return PropertyDevelopmentResource::collection($developments);
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
