<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Branding\LandlordBrandingManagementService;
use App\Http\Api\v1\Requests\UpdateBrandingRequest;
use App\Models\Landlord\Landlord;
use App\Traits\HasLogoFiles;
use Illuminate\Http\JsonResponse;

class LandlordBrandingController
{
    use HasLogoFiles;

    public function __construct(
        private readonly LandlordBrandingManagementService $brandingService
    ) {}

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $landlord = Landlord::singleton();
        $newData = $request->validated();

        $uploadedLogoUrls = $this->processLogoUploads($request);

        $pwaVariants = [];
        if ($request->hasFile('logo_settings.pwa_icon')) {
            $pwaVariants = $this->generatePwaIconVariants(
                sourceFile: $request->file('logo_settings.pwa_icon'),
            );
        }

        $brandingData = $this->brandingService->update(
            $landlord,
            $newData,
            $uploadedLogoUrls,
            $pwaVariants
        );

        return response()->json([
            'message' => 'Branding data updated successfully.',
            'branding_data' => $brandingData,
        ]);
    }
}
