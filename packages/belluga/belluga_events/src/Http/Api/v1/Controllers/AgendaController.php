<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Controllers;

use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Contracts\EventDiscoveryFilterCatalogContract;
use Belluga\Events\Contracts\EventRequestLifecycleTraceContract;
use Belluga\Events\Contracts\EventTenantContextContract;
use Belluga\Events\Http\Api\v1\Requests\AgendaIndexRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class AgendaController extends Controller
{
    public function __construct(
        private readonly EventQueryService $eventQueryService,
        private readonly EventTenantContextContract $tenantContext,
        private readonly EventDiscoveryFilterCatalogContract $eventDiscoveryFilterCatalog,
        private readonly EventRequestLifecycleTraceContract $requestLifecycleTrace,
    ) {}

    public function index(AgendaIndexRequest $request): JsonResponse
    {
        $this->requestLifecycleTrace->record('endpoint.agenda.controller.enter');

        $user = $request->user();
        $userId = $user ? (string) $user->getAuthIdentifier() : null;
        $payload = $this->eventQueryService->fetchAgenda($request->validated(), $userId);

        $this->requestLifecycleTrace->record('endpoint.agenda.payload_ready', [
            'items_count' => is_array($payload['items'] ?? null) ? count($payload['items']) : null,
        ]);

        $this->requestLifecycleTrace->record('endpoint.agenda.response_catalog.start');
        $responseCatalog = $this->eventDiscoveryFilterCatalog->buildCanonicalCatalog(
            'home.events',
            is_array($payload['discovery_filter_facets'] ?? null)
                ? $payload['discovery_filter_facets']
                : null,
            $request->getSchemeAndHttpHost()
        );
        $this->requestLifecycleTrace->record('endpoint.agenda.response_catalog.complete');

        $response = [
            'tenant_id' => $this->tenantContext->resolveCurrentTenantId(),
            'items' => $payload['items'],
            'has_more' => $payload['has_more'],
            'discovery_filter_facets' => $payload['discovery_filter_facets'] ?? null,
            'discovery_filter_catalog' => $responseCatalog,
        ];

        $this->requestLifecycleTrace->record('endpoint.agenda.response_ready');

        return response()->json($response);
    }
}
