<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Support\Helpers\ArrayReplaceEmptyAware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandingController extends Controller
{
    public function showBrandingData(Request $request): JsonResponse
    {
        $tenant = Tenant::current();
        $landlord = Landlord::singleton();

        if($tenant?->branding_data){
            $final_branding_data = ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
                mainArray: $landlord->branding_data,
                overrideArray: $tenant->branding_data
            );
        }else{
            $final_branding_data = $landlord->branding_data;
        }

        $export_data = [
            "theme_data_settings" => $final_branding_data['theme_data_settings'],
            "logo_settings" => [
                "light_logo_uri" => $request->root()."/logo-light.png",
                "dark_logo_uri" => $request->root()."/logo-dark.png",
                "light_icon_uri" => $request->root()."/icon-light.png",
                "dark_icon_uri" => $request->root()."/icon-dark.png",
                "favicon_uri" => $request->root()."/favicon.ico",
            ],
            "pwa_icon" => [
                "icon192_uri" => $request->root()."/icon/icon-192x192.png",
                "icon512_uri" => $request->root()."/icon/icon-512x512.png",
                "icon_maskable512_uri" => $request->root()."/icon/icon-maskable-512x512.png",
            ]
        ];

        return response()->json($export_data);
    }

    /**
     * Dynamically generates the manifest.json file for the current tenant.
     */
    public function getManifest(Request $request): JsonResponse
    {
        $manifest_data = $this->_buildManifest($request);

        return response()->json($manifest_data)
            ->header('Content-Type', 'application/manifest+json');
    }

    protected function _buildManifest(Request $request): array {
        $tenant = Tenant::current();

        if ($tenant) {
            $manifest_content = $this->_buildTenantManifest();
        }else{
            $manifest_content = $this->_buildLandlordManifest();
        }

        $manifest_content['icons'] = [
            [
                "src" =>  $request->root()."/icon/icon-192x192.png",
                "sizes" => "192x192",
                "type" => "image/png"
            ],
            [
                "src" => $request->root()."/icon/icon-512x512.png",
                "sizes"=> "512x512",
                "type" => "image/png"
            ],
            [
                "src" => $request->root()."/icon/icon-maskable-512x512.png",
                "sizes" => "512x512",
                "type" => "image/png",
                "purpose" => "maskable"
            ]
        ];

        return $manifest_content;
    }

    protected function _buildTenantManifest(): array {

        $tenant = Tenant::current();
        return $tenant->getManifestData();
    }

    protected function _buildLandlordManifest(): array {
        $landlord = Landlord::singleton();
        return $landlord->getManifestData();
    }

    public function getLogoSettingsParameter(String $parameter): String {
        $landlord_branding_data = Landlord::singleton()->branding_data;
        $tenant_branding_data = Tenant::current()?->branding_data;

        return ($tenant_branding_data['logo_settings'][$parameter] ?? "") ?: $landlord_branding_data['logo_settings'][$parameter];
    }

    public function getPwaIconParameter(String $parameter): String {
        $landlord_branding_data = Landlord::singleton()->branding_data;
        $tenant_branding_data = Tenant::current()?->branding_data;

        return ($tenant_branding_data['pwa_icon'][$parameter] ?? "") ?: $landlord_branding_data['pwa_icon'][$parameter];
    }

    public function getFavicon(): Response|BinaryFileResponse
    {
        $iconPath = $this->getLogoSettingsParameter('favicon_uri');
        return $this->serveFileFromStorage($iconPath);
    }

    public function getLogoLight(): Response|BinaryFileResponse
    {
        $iconPath = $this->getLogoSettingsParameter('light_logo_uri');
        return $this->serveFileFromStorage($iconPath);
    }

    public function getLogoDark(): Response|BinaryFileResponse
    {
        $iconPath = $this->getLogoSettingsParameter('dark_logo_uri');
        return $this->serveFileFromStorage($iconPath);
    }

    public function getMaskableIcon(): Response|BinaryFileResponse
    {
        $iconPath = $this->getPwaIconParameter('icon_maskable512_uri');
        return $this->serveFileFromStorage($iconPath);
    }

    public function getIcon192(): Response|BinaryFileResponse
    {
        $iconPath = $this->getPwaIconParameter('icon192_uri');
        return $this->serveFileFromStorage($iconPath);
    }

    public function getIcon512(): Response|BinaryFileResponse
    {
        $iconPath = $this->getPwaIconParameter('icon512_uri');
        return $this->serveFileFromStorage($iconPath);
    }

    private function serveFileFromStorage(?string $path): Response|BinaryFileResponse
    {
        $urlPath = $path ? parse_url($path, PHP_URL_PATH) : null;
        $localPath = $urlPath ? Str::after($urlPath, '/storage/') : null;

        if (!$localPath || !Storage::disk('public')->exists($localPath)) {
            return response('', 404);
        }

        return response()->file(Storage::disk('public')->path($localPath));
    }
}
