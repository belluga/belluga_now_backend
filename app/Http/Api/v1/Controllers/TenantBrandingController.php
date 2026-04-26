<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Telemetry\TelemetryEmitter;
use App\Application\Tenants\TenantBrandingManagementService;
use App\Http\Api\v1\Requests\UpdateBrandingRequest;
use App\Models\Landlord\Tenant;
use App\Traits\HasLogoFiles;
use Illuminate\Http\JsonResponse;

class TenantBrandingController
{
    use HasLogoFiles;

    public function __construct(
        private readonly TenantBrandingManagementService $brandingService,
        private readonly BrandingPublicWebMediaService $brandingPublicWebMediaService,
        private readonly TelemetryEmitter $telemetry
    ) {}

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $validated = $request->validated();
        $uploadedLogos = $this->processLogoUploads($request, $tenant);
        if ($request->hasFile('public_web_metadata.default_image')) {
            $validated['public_web_metadata']['default_image'] = $this->brandingPublicWebMediaService->storeDefaultImage(
                $request->getSchemeAndHttpHost(),
                $tenant,
                $request->file('public_web_metadata.default_image')
            );
        }

        $pwaVariants = [];
        if ($request->hasFile('logo_settings.pwa_icon')) {
            $pwaVariants = $this->generatePwaIconVariants(
                sourceFile: $request->file('logo_settings.pwa_icon'),
                brandable: $tenant,
                baseUrl: $request->getSchemeAndHttpHost(),
            );
        }

        $brandingData = $this->brandingService->update(
            $tenant,
            $validated,
            $uploadedLogos,
            $pwaVariants
        );

        $user = $request->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'tenant_branding_updated',
                userId: (string) $user->_id,
                properties: [
                    'changed_fields' => array_keys($validated),
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json([
            'message' => 'Branding data updated successfully.',
            'branding_data' => $brandingData,
        ]);
    }
}
