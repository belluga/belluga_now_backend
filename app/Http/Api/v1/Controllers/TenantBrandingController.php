<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\UpdateBrandingRequest;
use App\Models\Landlord\Tenant;
use App\Support\Helpers\ArrayReplaceEmptyAware;
use App\Traits\HasLogoFiles;
use Illuminate\Http\JsonResponse;

class TenantBrandingController
{

    use HasLogoFiles;

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $tenant = Tenant::current();
        $newData = $request->validated();
        $uploadedLogoUrls = $this->processLogoUploads($request);

        $brandingArray = $newData;
        $brandingArray['logo_settings'] = $uploadedLogoUrls;

        if ($request->hasFile("logo_settings.pwa_icon")) {
            $brandingArray['pwa_icon'] = $this->generatePwaIconVariants(
                sourcePath: $request->file("logo_settings.pwa_icon")->getRealPath(),
            );
        }

        $tenantBrandingArray = $this->buildTenantBrandingArrayWithEmpties($newData, $uploadedLogoUrls);

        if($tenant->branding_data){
            $tenant->branding_data = ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
                mainArray:  $tenant->branding_data,
                overrideArray: $tenantBrandingArray
            );
        }else{
            $tenant->branding_data = $tenantBrandingArray;
        }

        $tenant->save();

        return response()->json([
            'message' => 'Branding data updated successfully.',
            'branding_data' => $tenant->branding_data,
        ]);
    }

    /**
     * Build a complete tenant branding array from incoming data and uploaded URLs.
     * Missing values are saved as empty strings.
     *
     * Expected structure:
     * - logoSettings: 6 keys
     * - themeDataSettings.darkSchemeData.primarySeedColor, secondarySeedColor
     * - themeDataSettings.lightSchemeData.primarySeedColor, secondarySeedColor
     */
    private function buildTenantBrandingArrayWithEmpties(array $newData, array $uploadedLogoUrls): array
    {
        // Build non-PWA logo fields as strings
        $logo = [];
        foreach ($this->logoKeys as $k) {
            if ($k === 'pwa_icon') {
                continue;
            }
            $logo[$k] = $uploadedLogoUrls[$k]
                ?? (string)($newData['logo_settings'][$k] ?? '')
                ?? '';
        }

        // Build nested PWA icon structure
        $pwa = [];
        // If controller generated variants, merge them
        if (!empty($uploadedLogoUrls['__pwa_variants'] ?? [])) {
            $pwa = array_merge($pwa, $uploadedLogoUrls['__pwa_variants']);
        }
        // If a new source file was uploaded, store its public URL as source_uri
        if (!empty($uploadedLogoUrls['pwa_icon'] ?? '')) {
            $pwa['source_uri'] = $uploadedLogoUrls['pwa_icon'];
        }
        // Merge any incoming pwa_icon keys from request (kept if API sends them)
        if (!empty($newData['logo_settings']['pwa_icon'] ?? [])) {
            $pwa = array_merge((array)$newData['logo_settings']['pwa_icon'], $pwa);
        }
        // Ensure keys exist even if empty
        $pwa = array_merge([
            'source_uri' => '',
            'icon192_uri' => '',
            'icon512_uri' => '',
            'icon_maskable512_uri' => '',
        ], $pwa);

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
            'pwa_icon' => $pwa,
        ];
    }

}
