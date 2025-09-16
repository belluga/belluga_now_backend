<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandingController extends Controller
{
    /**
     * Dynamically generates the manifest.json file for the current tenant.
     */
    public function getManifest(Request $request): JsonResponse
    {
        $manifest_data = $this->_buildManifest();

        return response()->json($manifest_data)
            ->header('Content-Type', 'application/manifest+json');
    }

    protected function _buildManifest(): array {
        $tenant = Tenant::current();

        if ($tenant) {
            $manifest_content = $this->_buildTenantManifest();
        }else{
            $manifest_content = $this->_buildLandlordManifest();
        }

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
        $landlord = Landlord::singleton();
        $iconPath = $landlord->brandingData->icon_settings->faviconUrl;

        return $this->serveFileFromStorage($iconPath);
    }

    public function getMaskableIcon(): Response|BinaryFileResponse
    {
        $landlord = Landlord::singleton();
        $iconPath = $landlord->brandingData->pwa_icon->icon_maskable512Uri;

        return $this->serveFileFromStorage($iconPath);
    }

    public function getIcon192(): Response|BinaryFileResponse
    {
        $landlord = Landlord::singleton();

        $iconPath = $landlord->brandingData->pwa_icon->icon192Uri;

        return $this->serveFileFromStorage($iconPath);
    }

    public function getIcon512(): Response|BinaryFileResponse
    {
        $landlord = Landlord::singleton();
        $iconPath = $landlord->brandingData->pwa_icon->icon512Uri;

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
