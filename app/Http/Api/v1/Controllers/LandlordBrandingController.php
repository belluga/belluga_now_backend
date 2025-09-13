<?php

namespace App\Http\Api\v1\Controllers;

use App\DataObjects\Branding\BrandingData;
use App\Http\Api\v1\Requests\UpdateBrandingRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class LandlordBrandingController extends Controller
{

    protected array $logoKeys = ['lightLogoUri', 'darkLogoUri', 'lightIconUri', 'darkIconUri', 'faviconUri'];

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $landlord = Landlord::singleton();
        $newData = $request->validated();

        // 1) Process logo uploads into URLs (no merging with landlord here)
        $uploadedLogoUrls = $this->processLogoUploads($request);

        // 2) Build a complete Tenant Branding array where all missing fields are empty strings
        $brandingArray = $newData;
        $brandingArray['logoSettings'] = $uploadedLogoUrls;
        $final_branding = array_replace_recursive($landlord->branding_data->toArray(), $brandingArray);

        // 3) Create a BrandingData DTO from the tenant-only array and save it
        $newBrandingData = BrandingData::fromArray($final_branding);
        $landlord->branding_data = $newBrandingData;
        $landlord->save();

        return response()->json([
            'message' => 'Branding data updated successfully.',
            'branding_data' => $landlord->branding_data->toArray(),
        ]);
    }

    /**
     * GET: Return merged branding (landlord as defaults + tenant overrides), always full.
     */
    public function show(): JsonResponse
    {
        $landlord = Landlord::singleton();
        return response()->json($landlord->branding_data->toArray());
    }

    protected function _filterEmptyBrandingValues(array $branding_array): array {

        foreach($branding_array as $key => $value) {
            if(is_array($value)) {
                $branding_array[$key] = $this->_filterEmptyBrandingValues($value);
            } else {
                if(empty($value)) {
                    unset($branding_array[$key]);
                }
            }
        }

        return $branding_array;
    }

    // Updated to return a simple array of new URLs
    private function processLogoUploads(UpdateBrandingRequest $request): array
    {
        $tenant = Tenant::current();

        $urls = [];
        foreach ($this->logoKeys as $key) {
            $fileKey = "logoSettings.{$key}";
            if ($request->hasFile($fileKey)) {
                $path = $request->file($fileKey)->store("landlord/logos", 'public');
                $urls[$key] = Storage::disk('public')->url($path);
            }
        }

        return $urls;
    }

    /**
     * Build a complete tenant branding array from incoming data and uploaded URLs.
     * Missing values are saved as empty strings.
     *
     * Expected structure:
     * - logoSettings: 5 keys
     * - themeDataSettings.darkSchemeData.primarySeedColor, secondarySeedColor
     * - themeDataSettings.lightSchemeData.primarySeedColor, secondarySeedColor
     */
    private function buildTenantBrandingArrayWithEmpties(array $newData, array $uploadedLogoUrls): array
    {
        // Normalize logoSettings
        $logo = [];
        foreach ($this->logoKeys as $k) {
            // Priority: uploaded URL > provided string > empty
            $logo[$k] = $uploadedLogoUrls[$k]
                ?? (string)($newData['logoSettings'][$k] ?? '')
                ?? '';
        }

        // Normalize theme data (fill empties for missing)
        $dark = [
            'primarySeedColor'   => (string)($newData['themeDataSettings']['darkSchemeData']['primarySeedColor'] ?? ''),
            'secondarySeedColor' => (string)($newData['themeDataSettings']['darkSchemeData']['secondarySeedColor'] ?? ''),
        ];
        $light = [
            'primarySeedColor'   => (string)($newData['themeDataSettings']['lightSchemeData']['primarySeedColor'] ?? ''),
            'secondarySeedColor' => (string)($newData['themeDataSettings']['lightSchemeData']['secondarySeedColor'] ?? ''),
        ];

        return [
            'logoSettings' => $logo,
            'themeDataSettings' => [
                'darkSchemeData' => $dark,
                'lightSchemeData' => $light,
            ],
        ];
    }
}
