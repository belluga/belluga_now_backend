<?php

namespace App\Http\Api\v1\Controllers;

use App\DataObjects\Branding\BrandingData;
use App\Http\Api\v1\Requests\UpdateBrandingRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Landlord;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Support\Arr;

class LandlordBrandingController extends Controller
{

    protected array $logoKeys = ['light_logo_uri', 'dark_logo_uri', 'light_icon_uri', 'dark_icon_uri', 'favicon_uri'];

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $landlord = Landlord::singleton();
        $newData = $request->validated();

        // 1) Process logo uploads into URLs (no merging with landlord here)
        $uploadedLogoUrls = $this->processLogoUploads($request);



        // 2) Build a complete Tenant Branding array where all missing fields are empty strings
        $brandingArray = $newData;
        $brandingArray['logo_settings'] = $uploadedLogoUrls;

        if (isset($uploadedLogoUrls['logo_settings']['__pwa_variants'])) {
            $brandingArray['logo_settings']['pwa_icon'] = array_merge(
                (array)($uploadedLogoUrls['logo_settings']['pwa_icon'] ?? []),
                $uploadedLogoUrls['__pwa_variants']
            );
            unset($brandingArray['__pwa_variants']);
        }

        $current_branding = $this->_filterEmptyBrandingValues($landlord->branding_data->toArray());

        $final_branding = array_replace_recursive($current_branding, $brandingArray);

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
        $urls = [];
        foreach ($this->logoKeys as $key) {
            $fileKey = "logo_settings.{$key}";
            if ($request->hasFile($fileKey)) {
                $file = $request->file($fileKey);

                $baseName = str_ends_with($key, '_uri') ? substr($key, 0, -4) : $key;
                $extension = $key === 'favicon_uri'
                    ? 'ico'
                    : ($file->getClientOriginalExtension() ?: 'png');

                $directory = "storage/landlord/logos";
                $fileName = "{$baseName}.{$extension}";
                $path = "{$directory}/{$fileName}";

                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }

                $file->storeAs($directory, $fileName, 'public');
                $urls[$key] = Storage::disk('public')->url($path);

                if ($key === 'pwa_icon') {

                    $pwa_variants = $this->generatePwaIconVariants(
                        sourcePath: $file->getRealPath(),
                        baseDir: 'storage/landlord/pwa'
                    );

                    $urls['pwa_icon'] = [
                        "source_uri" => $urls['pwa_icon'],
                        ...$pwa_variants

                    ];
                }
            }
        }

        return $urls;
    }

    /**
     * Generate PWA icon variants (192, 512, and maskable 512) from a source image.
     */
    private function generatePwaIconVariants(string $sourcePath, string $baseDir): array

    {
        Storage::disk('public')->makeDirectory($baseDir);

        $icon192 = "{$baseDir}/icon-192x192.png";
        $icon512 = "{$baseDir}/icon-512x512.png";
        $iconMaskable512 = "{$baseDir}/icon-maskable-512x512.png";

        // 192x192: contain + center on transparent canvas
        $tmp192 = Image::read($sourcePath)->contain(192, 192);
        $canvas192 = Image::create(192, 192);
        $canvas192->place($tmp192, 'center')
            ->save(Storage::disk('public')->path($icon192));

        // 512x512: contain + center
        $tmp512 = Image::read($sourcePath)->contain(512, 512);
        $canvas512 = Image::create(512, 512);
        $canvas512->place($tmp512, 'center')
            ->save(Storage::disk('public')->path($icon512));

        // Maskable 512x512 with safe padding (~80% content area)
        $canvas = Image::create(512, 512);
        $content = Image::read($sourcePath)->contain(410, 410);
        $canvas->place($content, 'center')
            ->save(Storage::disk('public')->path($iconMaskable512));

        return [
            'icon_192_uri' => Storage::disk('public')->url($icon192),
            'icon_512_uri' => Storage::disk('public')->url($icon512),
            'icon_maskable_512_uri' => Storage::disk('public')->url($iconMaskable512),
        ];
    }
}
