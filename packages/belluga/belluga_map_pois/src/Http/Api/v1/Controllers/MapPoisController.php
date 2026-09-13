<?php

declare(strict_types=1);

namespace Belluga\MapPois\Http\Api\v1\Controllers;

use Belluga\MapPois\Application\MapPoiQueryService;
use Belluga\MapPois\Http\Api\v1\Requests\MapPoiLookupRequest;
use Belluga\MapPois\Http\Api\v1\Requests\MapPoisIndexRequest;
use Belluga\MapPois\Http\Api\v1\Requests\MapPoisNearRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class MapPoisController extends Controller
{
    private const MAX_SCENE_PAYLOAD_BYTES = 200 * 1024;

    public function __construct(private readonly MapPoiQueryService $queryService) {}

    public function index(MapPoisIndexRequest $request): JsonResponse
    {
        $payload = $this->queryService->stacks(
            $request->validated(),
            $request->user()?->timezone
        );
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > self::MAX_SCENE_PAYLOAD_BYTES) {
            return response()->json([
                'message' => 'The map scene payload is too large.',
                'errors' => [
                    'payload' => ['map_payload_too_large'],
                ],
            ], 422);
        }

        return response()->json($payload);
    }

    public function lookup(MapPoiLookupRequest $request): JsonResponse
    {
        $lookupPayload = $this->queryService->lookup(
            $request->validated(),
            $request->user()?->timezone
        );

        if ($lookupPayload === null) {
            return response()->json(
                [
                    'message' => 'POI not found.',
                ],
                404
            );
        }

        return response()->json($lookupPayload);
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

}
