<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Controllers;

use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Http\Api\v1\Requests\AgendaIndexRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;

class AgendaController extends Controller
{
    public function __construct(
        private readonly EventQueryService $eventQueryService
    ) {
    }

    public function index(AgendaIndexRequest $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $payload = $this->eventQueryService->fetchAgenda($request->validated(), $request->user());

        return response()->json([
            'tenant_id' => (string) $tenant->_id,
            'items' => $payload['items'],
            'has_more' => $payload['has_more'],
        ]);
    }
}
