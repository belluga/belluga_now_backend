<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Events\EventTypeRegistryManagementService;
use App\Application\Events\EventTypeRegistryService;
use App\Http\Api\v1\Requests\EventTypeStoreRequest;
use App\Http\Api\v1\Requests\EventTypeUpdateRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventTypesController extends Controller
{
    public function __construct(
        private readonly EventTypeRegistryService $registryService,
        private readonly EventTypeRegistryManagementService $managementService,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->registryService->registry(),
        ]);
    }

    public function store(EventTypeStoreRequest $request): JsonResponse
    {
        $entry = $this->managementService->create($request->validated());

        return response()->json(['data' => $entry], 201);
    }

    public function update(EventTypeUpdateRequest $request): JsonResponse
    {
        $eventTypeId = (string) $request->route('event_type', '');
        $entry = $this->managementService->update($eventTypeId, $request->validated());

        return response()->json(['data' => $entry]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $eventTypeId = (string) $request->route('event_type', '');
        $this->managementService->delete($eventTypeId);

        return response()->json([]);
    }
}
