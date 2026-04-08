<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileRegistryManagementService;
use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Http\Api\v1\Requests\AccountProfileTypeStoreRequest;
use App\Http\Api\v1\Requests\AccountProfileTypeUpdateRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountProfileTypesController extends Controller
{
    public function __construct(
        private readonly AccountProfileRegistryService $registryService,
        private readonly AccountProfileRegistryManagementService $managementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->registryService->registry($request->getSchemeAndHttpHost()),
        ]);
    }

    public function store(AccountProfileTypeStoreRequest $request): JsonResponse
    {
        $entry = $this->managementService->create($request, $request->validated());

        return response()->json(['data' => $entry], 201);
    }

    public function update(
        AccountProfileTypeUpdateRequest $request
    ): JsonResponse {
        $profileType = (string) $request->route('profile_type', '');
        $entry = $this->managementService->update($request, $profileType, $request->validated());

        return response()->json(['data' => $entry]);
    }

    public function mapPoiProjectionImpact(Request $request): JsonResponse
    {
        $profileType = (string) $request->route('profile_type', '');
        $count = $this->managementService->previewDisableProjectionCount($profileType);

        return response()->json([
            'data' => [
                'profile_type' => $profileType,
                'projection_count' => $count,
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $profileType = (string) $request->route('profile_type', '');
        $this->managementService->delete($profileType);

        return response()->json([]);
    }
}
