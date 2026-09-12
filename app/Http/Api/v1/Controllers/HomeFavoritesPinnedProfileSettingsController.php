<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\HomeFavoritesPinnedProfileService;
use Belluga\Settings\Application\SettingsKernelService;
use Belluga\Settings\Contracts\TenantEnvironmentSnapshotRepairContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HomeFavoritesPinnedProfileSettingsController
{
    public function __construct(
        private readonly SettingsKernelService $settings,
        private readonly HomeFavoritesPinnedProfileService $pinnedProfile,
        private readonly TenantEnvironmentSnapshotRepairContract $tenantEnvironmentSnapshotRepair,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->pinnedProfile->adminProjection(
                $this->settings->namespaceValues(
                    'tenant',
                    $request->user(),
                    HomeFavoritesPinnedProfileService::NAMESPACE,
                ),
            ),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $value = $this->settings->patchNamespace(
            'tenant',
            $request->user(),
            HomeFavoritesPinnedProfileService::NAMESPACE,
            is_array($payload) ? $payload : [],
        );
        $this->tenantEnvironmentSnapshotRepair->repairCurrentTenant(
            'tenant_settings_namespace_updated_sync',
            [
                'trigger' => 'tenant_settings_patch',
                'namespace' => HomeFavoritesPinnedProfileService::NAMESPACE,
                'changed_fields' => array_keys($payload),
            ],
        );

        return response()->json([
            'data' => $this->pinnedProfile->adminProjection($value),
        ]);
    }
}
