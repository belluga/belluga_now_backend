<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Tenants\TenantBrandingManagementService;
use App\Http\Api\v1\Requests\UpdateBrandingRequest;
use App\Models\Landlord\Tenant;
use App\Traits\HasLogoFiles;
use Illuminate\Http\JsonResponse;

class TenantBrandingController
{
    use HasLogoFiles;

    public function __construct(
        private readonly TenantBrandingManagementService $brandingService
    ) {
    }

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $validated = $request->validated();
        $uploadedLogos = $this->processLogoUploads($request);

        $pwaVariants = [];
        if ($request->hasFile('logo_settings.pwa_icon')) {
            $pwaVariants = $this->generatePwaIconVariants(
                sourceFile: $request->file('logo_settings.pwa_icon')
            );
        }

        $brandingData = $this->brandingService->update(
            $tenant,
            $validated,
            $uploadedLogos,
            $pwaVariants
        );

        return response()->json([
            'message' => 'Branding data updated successfully.',
            'branding_data' => $brandingData,
        ]);
    }

}
