<?php

namespace App\Http\Api\v1\Controllers;

use App\DataObjects\Branding\BrandingData;
use App\Http\Api\v1\Requests\UpdateBrandingRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class TenantBrandingController extends Controller
{
    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $tenant = Tenant::current();
        $newData = $request->validated();

        // 1. Get default and current data, converting them to arrays
        $defaultArray = ($tenant->landlord->branding_data ?? $this->createDefaultBrandingData())->toArray();
        $currentArray = $tenant->branding_data ? $tenant->branding_data->toArray() : [];

        // 2. Perform a "deep merge" with the correct priority: new data > current data > default data
        $mergedData = array_merge_recursive($defaultArray, $currentArray, $newData);

        // 3. Process file uploads separately, as they are not part of the text-based merge
        // This logic overrides the URLs in our merged data array if a new file is sent.
        $logoUrls = $this->processLogoUploads($request, $tenant);
        if (!empty($logoUrls)) {
            $mergedData['logoSettings'] = array_merge($mergedData['logoSettings'], $logoUrls);
        }

        // 4. Create the final Data Object from the fully merged data
        $newBrandingData = BrandingData::fromArray($mergedData);

        // 5. Save the result
        $tenant->branding_data = $newBrandingData;
        $tenant->save();

        return response()->json([
            'message' => 'Branding data updated successfully.',
            'branding_data' => $tenant->branding_data,
        ]);
    }

    // Updated to return a simple array of new URLs
    private function processLogoUploads(UpdateBrandingRequest $request): array
    {
        $tenant = Tenant::current();

        $urls = [];
        $logoKeys = ['lightLogoUri', 'darkLogoUri', 'lightIconUri', 'darkIconUri'];

        foreach ($logoKeys as $key) {
            $fileKey = "logoSettings.{$key}";
            if ($request->hasFile($fileKey)) {
                $path = $request->file($fileKey)->store("tenants/{$tenant->slug}/logos", 'public');
                $urls[$key] = Storage::disk('public')->url($path);
            }
        }

        return $urls;
    }

     protected function createDefaultBrandingData(): array {

     }
}
