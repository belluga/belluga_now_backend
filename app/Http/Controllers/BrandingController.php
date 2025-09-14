<?php

namespace App\Http\Controllers;

use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

        return [
            'name'             => $landlord->name,
            'short_name'       => $landlord->name,
            'start_url'        => '/',
            'display'          => 'standalone',
            'background_color' => $landlord->branding_data->theme_data_settings->light_scheme_data->primary_seed_color,
            'theme_color'      => $landlord->branding_data->theme_data_settings->light_scheme_data->primary_seed_color,
            'description'      => $landlord->description,
            'icons' => []
        ];
    }

    /**
     * Serves the favicon.ico file for the current tenant from storage.
     */
    public function getFavicon(Request $request): Response|BinaryFileResponse
    {
        $tenant = Tenant::current();

        // Path to a default favicon if tenant or its icon is not found
        $fallbackPath = 'defaults/favicon.ico';

        if (!$tenant || !$tenant->favicon_path) {
            return Storage::disk('public')->exists($fallbackPath)
                ? response()->file(Storage::disk('public')->path($fallbackPath))
                : response('', 404);
        }

        // Serve the tenant's specific favicon
        $path = $tenant->favicon_path; // e.g., 'tenants/unifast/favicon.ico'

        return Storage::disk('public')->exists($path)
            ? response()->file(Storage::disk('public')->path($path))
            : response('', 404);
    }
}
