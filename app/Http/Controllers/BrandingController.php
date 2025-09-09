<?php

namespace App\Http\Controllers;

use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

        return [
            'name'             => $tenant->name,
//            'short_name'       => $tenant->short_name,
            'short_name'       => "tssdfsdf",
            'start_url'        => '.',
            'display'          => 'standalone',
            'background_color' => $tenant->primary_color,
            'theme_color'      => $tenant->primary_color,
            'description'      => 'Portal do ' . $tenant->description,
            'icons' => [
                [
                    'src'   => $tenant->icon_192_url, // Full URL to the public asset
                    'sizes' => '192x192',
                    'type'  => 'image/png',
                ],
                [
                    'src'   => $tenant->icon_512_url,
                    'sizes' => '512x512',
                    'type'  => 'image/png',
                ],
            ],
        ];
    }

    protected function _buildLandlordManifest(): array {
        return [
            'name'             => 'Portal',
            'short_name'       => 'Portal',
            'start_url'        => '.',
            'display'          => 'standalone',
            'background_color' => '#ffffff',
            'theme_color'      => '#ffffff',
            'description'      => 'Portal do Portal',
            'icons' => []
        ];
    }

    /**
     * Serves the favicon.ico file for the current tenant from storage.
     */
    public function getFavicon(Request $request)
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
