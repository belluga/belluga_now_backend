<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\UpdateBrandingRequest;
use App\Models\Landlord\Landlord;
use App\Support\Helpers\ArrayReplaceEmptyAware;
use App\Traits\HasLogoFiles;
use Illuminate\Http\JsonResponse;

class LandlordBrandingController
{

    use HasLogoFiles;

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $landlord = Landlord::singleton();
        $newData = $request->validated();

        $uploadedLogoUrls = $this->processLogoUploads($request);

        $brandingArray = $newData;
        $brandingArray['logo_settings'] = $uploadedLogoUrls;

        if ($request->hasFile("logo_settings.pwa_icon")) {
            $brandingArray['pwa_icon'] = $this->generatePwaIconVariants(
                sourcePath: $request->file("logo_settings.pwa_icon")->getRealPath(),
            );
        }

        $landlord->branding_data = ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
            mainArray:  $landlord->branding_data,
            overrideArray: $brandingArray
        );
        $landlord->save();

        return response()->json([
            'message' => 'Branding data updated successfully.',
            'branding_data' => $landlord->branding_data,
        ]);
    }
}
