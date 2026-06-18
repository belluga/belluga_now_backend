<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Controllers;

use App\Application\RuntimeDiscoveryFilterCatalogService;
use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Contracts\EventTenantContextContract;
use Belluga\Events\Http\Api\v1\Requests\AgendaIndexRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class AgendaController extends Controller
{
    public function __construct(
        private readonly EventQueryService $eventQueryService,
        private readonly EventTenantContextContract $tenantContext,
        private readonly RuntimeDiscoveryFilterCatalogService $runtimeDiscoveryFilterCatalogService,
    ) {}

    public function index(AgendaIndexRequest $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user ? (string) $user->getAuthIdentifier() : null;
        $payload = $this->eventQueryService->fetchAgenda($request->validated(), $userId);

        return response()->json([
            'tenant_id' => $this->tenantContext->resolveCurrentTenantId(),
            'items' => $payload['items'],
            'has_more' => $payload['has_more'],
            'discovery_filter_facets' => $payload['discovery_filter_facets'] ?? null,
            'discovery_filter_catalog' => $this->runtimeDiscoveryFilterCatalogService
                ->buildCanonicalCatalog(
                    'home.events',
                    is_array($payload['discovery_filter_facets'] ?? null)
                        ? $payload['discovery_filter_facets']
                        : null,
                    $request->getSchemeAndHttpHost()
                ),
        ]);
    }
}
