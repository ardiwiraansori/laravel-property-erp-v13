<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StorePropertyDevelopmentRequest;
use App\Http\Resources\MasterData\PropertyDevelopmentResource;
use App\Models\PropertyDevelopment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class PropertyDevelopmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $developments = PropertyDevelopment::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return PropertyDevelopmentResource::collection($developments);
    }

    public function store(
        StorePropertyDevelopmentRequest $request,
    ): JsonResponse {
        $development = PropertyDevelopment::query()->create(
            $request->validated(),
        );

        return PropertyDevelopmentResource::make($development)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
