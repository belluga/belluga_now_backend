<?php

namespace App\Http\Api\v1\Controllers;

use App\DataObjects\Branding\BrandingData;
use App\Http\Api\v1\Requests\UpdateBrandingRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class TenantBrandingController extends Controller
{

    protected array $logoKeys = ['light_logo_uri', 'dark_logo_uri', 'light_icon_uri', 'dark_icon_uri', 'favicon_uri', 'pwa_icon'];

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $tenant = Tenant::current();
        $newData = $request->validated();

        // 1) Process logo uploads into URLs (no merging with landlord here)
        $uploadedLogoUrls = $this->processLogoUploads($request);

        // If we generated PWA variants, move them into the expected nested structure
        if (isset($uploadedLogoUrls['__pwa_variants'])) {
            $newData['logo_settings']['pwa_icon'] = array_merge(
                (array)($newData['logo_settings']['pwa_icon'] ?? []),
                $uploadedLogoUrls['__pwa_variants']
            );
            unset($uploadedLogoUrls['__pwa_variants']);
        }

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
                $file = $request->file($fileKey);

                // Derive base name from key: strip "_uri" if present
                $baseName = str_ends_with($key, '_uri') ? substr($key, 0, -4) : $key;

                // Choose extension: favicon stays .ico; others prefer original or fallback to png
                $extension = $key === 'favicon_uri'
                    ? 'ico'
                    : ($file->getClientOriginalExtension() ?: 'png');

                $directory = "tenants/{$tenant->slug}/logos";
                $fileName = "{$baseName}.{$extension}";
                $path = "{$directory}/{$fileName}";

                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }

                $file->storeAs($directory, $fileName, 'public');
                $urls[$key] = Storage::disk('public')->url($path);

                // If this is the PWA source, generate the variants now and expose their URLs
                if ($key === 'pwa_icon') {
                    $variants = $this->generatePwaIconVariants(
                        sourcePath: $file->getRealPath(),
                        baseDir: "storage/tenants/{$tenant->slug}/pwa"
                    );
                    $urls['__pwa_variants'] = $variants;
                }
            }
        }

        return $urls;
    }


    /**
     * Generate PWA icon variants (192, 512, and maskable 512) from a source image.
     * Returns public URLs to the generated files.
     */
    private function generatePwaIconVariants(string $sourcePath, string $baseDir): array
    {
        Storage::disk('public')->makeDirectory($baseDir);

        $icon192 = "{$baseDir}/icon-192x192.png";
        $icon512 = "{$baseDir}/icon-512x512.png";
        $iconMaskable512 = "{$baseDir}/icon-maskable-512x512.png";

        // 192x192: contain within 192 and center on a 192 canvas
        $tmp192 = Image::read($sourcePath)->contain(192, 192);
        $canvas192 = Image::create(192, 192);
        $canvas192->place($tmp192, 'center')
            ->save(Storage::disk('public')->path($icon192));

        // 512x512: contain within 512 and center on a 512 canvas
        $tmp512 = Image::read($sourcePath)->contain(512, 512);
        $canvas512 = Image::create(512, 512);
        $canvas512->place($tmp512, 'center')
            ->save(Storage::disk('public')->path($icon512));

        // Maskable 512x512: safe area ~80% (410px), already non-cropping
        $canvasMask = Image::create(512, 512);
        $content = Image::read($sourcePath)->contain(410, 410);
        $canvasMask->place($content, 'center')
            ->save(Storage::disk('public')->path($iconMaskable512));

        return [
            'icon_192_uri' => Storage::disk('public')->url($icon192),
            'icon_512_uri' => Storage::disk('public')->url($icon512),
            'icon_maskable_512_uri' => Storage::disk('public')->url($iconMaskable512),
        ];

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
            'icon_192_uri' => '',
            'icon_512_uri' => '',
            'icon_maskable_512_uri' => '',
        ], $pwa);

        $logo['pwa_icon'] = $pwa;

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
