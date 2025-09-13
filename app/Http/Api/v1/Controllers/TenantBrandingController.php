<?php

namespace App\Http\Api\v1\Controllers;

use App\DataObjects\Branding\BrandingData;
use App\Http\Api\v1\Requests\UpdateBrandingRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class TenantBrandingController extends Controller
{

    protected array $logoKeys = ['light_logo_uri', 'dark_logo_uri', 'light_icon_uri', 'dark_icon_uri', 'favicon_uri'];

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $tenant = Tenant::current();
        $newData = $request->validated();

        // 1) Process logo uploads into URLs (no merging with landlord here)
        $uploadedLogoUrls = $this->processLogoUploads($request);

        // 2) Build a complete Tenant Branding array where all missing fields are empty strings
        $tenantBrandingArray = $this->buildTenantBrandingArrayWithEmpties($newData, $uploadedLogoUrls);

        // 3) Create a BrandingData DTO from the tenant-only array and save it
        $newBrandingData = BrandingData::fromArray($tenantBrandingArray);
        $tenant->branding_data = $newBrandingData;
        $tenant->save();

        return response()->json([
            'message' => 'Branding data updated successfully.',
            'branding_data' => $tenant->branding_data,
        ]);
    }

    /**
     * GET: Return merged branding (landlord as defaults + tenant overrides), always full.
     */
    public function show(): JsonResponse
    {
        $tenant = Tenant::current();
        $landlord = Landlord::singleton();

        $landlordArray = $landlord->branding_data?->toArray();
        $tenantArray = $this->_filterEmptyBrandingValues($tenant->branding_data->toArray());

        $merged = array_replace_recursive($landlordArray, $tenantArray);

        return response()->json($merged);
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
            $fileKey = "logo_settings.{$key}";
            if ($request->hasFile($fileKey)) {
                $path = $request->file($fileKey)->store("tenants/{$tenant->slug}/logos", 'public');
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
                ?? (string)($newData['logo_settings'][$k] ?? '')
                ?? '';
        }

        // Normalize theme data (fill empties for missing)
        $dark = [
            'primary_seed_color'   => (string)($newData['theme_data_settings']['dark_scheme_data']['primary_seed_color'] ?? ''),
            'secondary_seed_color' => (string)($newData['theme_data_settings']['dark_scheme_data']['secondary_seed_color'] ?? ''),
        ];
        $light = [
            'primary_seed_color'   => (string)($newData['theme_data_settings']['light_scheme_data']['primary_seed_color'] ?? ''),
            'secondary_seed_color' => (string)($newData['theme_data_settings']['light_scheme_data']['secondary_seed_color'] ?? ''),
        ];

        return [
            'logo_settings' => $logo,
            'theme_data_settings' => [
                'dark_scheme_data' => $dark,
                'light_scheme_data' => $light,
            ],
        ];
    }
}
