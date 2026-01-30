<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileRegistryManagementService;
use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Http\Controllers\Controller;
use App\Http\Api\v1\Requests\AccountProfileTypeStoreRequest;
use App\Http\Api\v1\Requests\AccountProfileTypeUpdateRequest;
use Illuminate\Http\JsonResponse;

class AccountProfileTypesController extends Controller
{
    public function __construct(
        private readonly AccountProfileRegistryService $registryService,
        private readonly AccountProfileRegistryManagementService $managementService,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->registryService->registry(),
        ]);
    }

    public function store(AccountProfileTypeStoreRequest $request): JsonResponse
    {
        $entry = $this->managementService->create($request->validated());

        return response()->json(['data' => $entry], 201);
    }

    public function update(
        AccountProfileTypeUpdateRequest $request
    ): JsonResponse {
        $profileType = (string) $request->route('profile_type', '');
        $entry = $this->managementService->update($profileType, $request->validated());

        return response()->json(['data' => $entry]);
    }

    public function destroy(string $profile_type): JsonResponse
    {
        $this->managementService->delete($profile_type);

        return response()->json([]);
    }
}
