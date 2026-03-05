<?php

declare(strict_types=1);

namespace Belluga\MapPois\Http\Api\v1\Controllers;

use Belluga\MapPois\Application\MapPoiQueryService;
use Belluga\MapPois\Http\Api\v1\Requests\MapFiltersRequest;
use Belluga\MapPois\Http\Api\v1\Requests\MapPoisIndexRequest;
use Belluga\MapPois\Http\Api\v1\Requests\MapPoisNearRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class MapPoisController extends Controller
{
    public function __construct(private readonly MapPoiQueryService $queryService)
    {
    }

    public function index(MapPoisIndexRequest $request): JsonResponse
    {
        return response()->json(
            $this->queryService->stacks(
                $request->validated(),
                $request->user()?->timezone
            )
        );
    }

    public function near(MapPoisNearRequest $request): JsonResponse
    {
        return response()->json(
            $this->queryService->near(
                $request->validated(),
                $request->user()?->timezone
            )
        );
    }

    public function filters(MapFiltersRequest $request): JsonResponse
    {
        return response()->json(
            $this->queryService->filters(
                $request->validated(),
                $request->user()?->timezone
            )
        );
    }
}
