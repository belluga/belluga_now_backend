<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Map\MapPoiQueryService;
use App\Http\Api\v1\Requests\MapPoiIndexRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class MapFiltersController extends Controller
{
    public function __construct(
        private readonly MapPoiQueryService $queryService
    ) {
    }

    public function index(MapPoiIndexRequest $request): JsonResponse
    {
        $payload = $this->queryService->fetchFilters($request->validated());

        return response()->json($payload);
    }
}
