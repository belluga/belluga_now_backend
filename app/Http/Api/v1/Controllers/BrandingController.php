<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Landlord\BrandingData;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandingController extends Controller
{
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

    public function getFavicon(): Response|BinaryFileResponse
    {
        $branding_data = BrandingData::getCurrentData();
        $iconPath = $branding_data['logo_settings']['favicon_uri'];

        return $this->serveFileFromStorage($iconPath);
    }

    public function getLogoLight(): Response|BinaryFileResponse
    {
        $branding_data = BrandingData::getCurrentData();
        $iconPath = $branding_data['logo_settings']['light_logo_uri'];

        return $this->serveFileFromStorage($iconPath);
    }

    public function getLogoDark(): Response|BinaryFileResponse
    {
        $branding_data = BrandingData::getCurrentData();
        $iconPath = $branding_data['logo_settings']['dark_logo_uri'];;

        return $this->serveFileFromStorage($iconPath);
    }

    public function getMaskableIcon(): Response|BinaryFileResponse
    {
        $branding_data = BrandingData::getCurrentData();
        $iconPath = $branding_data['pwa_icon']['icon_maskable512_uri'];

        return $this->serveFileFromStorage($iconPath);
    }

    public function getIcon192(): Response|BinaryFileResponse
    {
        $branding_data = BrandingData::getCurrentData();
        $iconPath = $branding_data['pwa_icon']['icon192_uri'];

        return $this->serveFileFromStorage($iconPath);
    }

    public function getIcon512(): Response|BinaryFileResponse
    {
        $branding_data = BrandingData::getCurrentData();
        $iconPath = $branding_data['pwa_icon']['icon512_uri'];

        return $this->serveFileFromStorage($iconPath);
    }

    private function serveFileFromStorage(?string $path): Response|BinaryFileResponse
    {
        // Remove o domínio para buscar o caminho local no storage
        $localPath = $path ? ltrim(parse_url($path, PHP_URL_PATH), '/') : null;

        if (!$localPath || !Storage::disk('public')->exists($localPath)) {
            return response('', 404);
        }
        return response()->file(Storage::disk('public')->path($localPath));
    }
}
