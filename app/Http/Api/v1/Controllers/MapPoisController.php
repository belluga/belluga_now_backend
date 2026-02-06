<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\MapPois\MapPoiQueryService;
use App\Http\Api\v1\Requests\MapFiltersRequest;
use App\Http\Api\v1\Requests\MapPoisIndexRequest;
use App\Http\Api\v1\Requests\MapPoisNearRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

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
