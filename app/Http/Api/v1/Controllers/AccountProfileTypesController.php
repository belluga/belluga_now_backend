<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileRegistryManagementService;
use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Application\Environment\TenantEnvironmentSnapshotService;
use App\Http\Api\v1\Requests\AccountProfileTypeStoreRequest;
use App\Http\Api\v1\Requests\AccountProfileTypeUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountProfileTypesController extends Controller
{
    public function __construct(
        private readonly AccountProfileRegistryService $registryService,
        private readonly AccountProfileRegistryManagementService $managementService,
        private readonly TenantEnvironmentSnapshotService $tenantEnvironmentSnapshotService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->registryService->registry($request->getSchemeAndHttpHost()),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $profileType = (string) $request->route('profile_type', '');
        $entry = $this->registryService->typeDefinition(
            $profileType,
            $request->getSchemeAndHttpHost(),
        );

        if ($entry === null) {
            abort(404, 'Account profile type not found.');
        }

        return response()->json(['data' => $entry]);
    }

    public function store(AccountProfileTypeStoreRequest $request): JsonResponse
    {
        $entry = $this->managementService->create($request, $request->validated());
        $this->repairTenantEnvironmentSnapshot(
            'profile_type_registry_created_sync',
            [
                'trigger' => 'account_profile_type_store',
                'profile_type' => (string) ($entry['type'] ?? ''),
            ],
        );

        return response()->json(['data' => $entry], 201);
    }

    public function update(
        AccountProfileTypeUpdateRequest $request
    ): JsonResponse {
        $profileType = (string) $request->route('profile_type', '');
        $entry = $this->managementService->update($request, $profileType, $request->validated());
        $this->repairTenantEnvironmentSnapshot(
            'profile_type_registry_updated_sync',
            [
                'trigger' => 'account_profile_type_update',
                'profile_type' => (string) ($entry['type'] ?? $profileType),
                'changed_fields' => array_keys($request->validated()),
            ],
        );

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
        $this->repairTenantEnvironmentSnapshot(
            'profile_type_registry_deleted_sync',
            [
                'trigger' => 'account_profile_type_delete',
                'profile_type' => $profileType,
            ],
        );

        return response()->json([]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function repairTenantEnvironmentSnapshot(string $reason, array $context = []): void
    {
        $tenant = Tenant::resolve();

        $this->tenantEnvironmentSnapshotService->repair(
            $tenant->fresh() ?? $tenant,
            $reason,
            $context,
        );
    }
}
