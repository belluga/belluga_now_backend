<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\StaticAssets\StaticProfileTypeRegistryManagementService;
use App\Application\StaticAssets\StaticProfileTypeRegistryService;
use App\Http\Controllers\Controller;
use App\Http\Api\v1\Requests\StaticProfileTypeStoreRequest;
use App\Http\Api\v1\Requests\StaticProfileTypeUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaticProfileTypesController extends Controller
{
    public function __construct(
        private readonly StaticProfileTypeRegistryService $registryService,
        private readonly StaticProfileTypeRegistryManagementService $managementService,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->registryService->registry(),
        ]);
    }

    public function store(StaticProfileTypeStoreRequest $request): JsonResponse
    {
        $entry = $this->managementService->create($request->validated());

        return response()->json(['data' => $entry], 201);
    }

    public function update(StaticProfileTypeUpdateRequest $request): JsonResponse
    {
        $profileType = (string) $request->route('profile_type', '');
        $entry = $this->managementService->update($profileType, $request->validated());

        return response()->json(['data' => $entry]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $profileType = (string) $request->route('profile_type', '');
        $this->managementService->delete($profileType);

        return response()->json([]);
    }
}
