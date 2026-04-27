<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\DiscoveryFilters\DiscoveryFilterPublicCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class DiscoveryFiltersController extends Controller
{
    public function show(
        string $tenant_domain,
        string $surface,
        DiscoveryFilterPublicCatalogService $catalog,
    ): JsonResponse {
        return response()->json(
            $catalog->catalogForSurface($surface, request()->getSchemeAndHttpHost())
        );
    }
}
