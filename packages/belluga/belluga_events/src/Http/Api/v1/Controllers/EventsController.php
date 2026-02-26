<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Controllers;

use Belluga\Events\Application\Events\EventManagementService;
use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Contracts\EventAccountResolverContract;
use Belluga\Events\Contracts\EventTenantContextContract;
use Belluga\Events\Http\Api\v1\Requests\EventIndexRequest;
use Belluga\Events\Http\Api\v1\Requests\EventStoreRequest;
use Belluga\Events\Http\Api\v1\Requests\EventUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class EventsController extends Controller
{
    public function __construct(
        private readonly EventQueryService $eventQueryService,
        private readonly EventManagementService $eventManagementService,
        private readonly EventAccountResolverContract $accountResolver,
        private readonly EventTenantContextContract $tenantContext
    ) {
    }

    public function index(EventIndexRequest $request): JsonResponse
    {
        $perPage = (int) ($request->get('page_size') ?? 15);
        $perPage = $perPage > 0 ? $perPage : 15;
        $accountContextId = $this->resolveAccountFromRoute($request);
        $accountId = $accountContextId ?? (string) ($request->get('account_id') ?? '');
        $accountProfileId = (string) ($request->get('account_profile_id') ?? '');
        $isAdmin = $this->isAdminContext($request);

        $paginator = $this->eventQueryService->paginateManagement(
            $request->query(),
            $request->boolean('archived'),
            $perPage,
            $isAdmin,
            $accountContextId,
            $accountId,
            $accountProfileId
        );

        return response()->json($paginator->toArray());
    }

    public function store(EventStoreRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $accountIdFromRoute = $this->resolveAccountFromRoute($request);

        if ($accountIdFromRoute) {
            $payload['account_id'] = $accountIdFromRoute;
        } else {
            $accountId = $payload['account_id'] ?? null;
            $accountProfileId = $payload['account_profile_id'] ?? null;
            if (! $accountId && ! $accountProfileId) {
                throw ValidationException::withMessages([
                    'account_id' => ['Account or account profile is required when creating on behalf.'],
                ]);
            }
        }

        $event = $this->eventManagementService->create($payload);

        return response()->json([
            'data' => $this->eventQueryService->formatManagementEvent($event),
        ], 201);
    }

    public function update(EventUpdateRequest $request, string $event_id): JsonResponse
    {
        $eventId = (string) ($request->route('event_id') ?? $event_id);

        $event = $this->eventQueryService->findByIdOrSlug($eventId);

        if (! $event) {
            abort(404, 'Event not found.');
        }

        $accountId = $this->resolveAccountFromRoute($request);
        if ($accountId && ! $this->eventQueryService->eventBelongsToAccount($event, $accountId)) {
            abort(404, 'Event not found.');
        }

        $updated = $this->eventManagementService->update($event, $request->validated());

        return response()->json([
            'data' => $this->eventQueryService->formatManagementEvent($updated),
        ]);
    }

    public function destroy(string $event_id): JsonResponse
    {
        $eventId = (string) (request()->route('event_id') ?? $event_id);
        $event = $this->eventQueryService->findByIdOrSlug($eventId);

        if (! $event) {
            abort(404, 'Event not found.');
        }

        $accountId = $this->resolveAccountFromRoute(request());
        if ($accountId && ! $this->eventQueryService->eventBelongsToAccount($event, $accountId)) {
            abort(404, 'Event not found.');
        }

        $this->eventManagementService->delete($event);

        return response()->json();
    }

    public function show(Request $request, string $event_id): JsonResponse
    {
        $eventId = (string) ($request->route('event_id') ?? $event_id);
        $event = $this->eventQueryService->findByIdOrSlug($eventId);

        if (! $event) {
            abort(404, 'Event not found.');
        }

        $accountId = $this->resolveAccountFromRoute($request);
        if ($accountId && ! $this->eventQueryService->eventBelongsToAccount($event, $accountId)) {
            abort(404, 'Event not found.');
        }

        if (! $this->isAdminContext($request)) {
            $this->eventQueryService->assertPublicVisible($event);
        }

        return response()->json([
            'tenant_id' => $this->tenantContext->resolveCurrentTenantId(),
            'data' => $this->eventQueryService->formatEvent($event, $this->resolveAuthenticatedUserId($request)),
        ]);
    }

    private function isAdminContext(Request $request): bool
    {
        if ($request->route('account_slug')) {
            return true;
        }

        return str_starts_with($request->path(), 'admin/api/v1');
    }

    private function resolveAccountFromRoute(Request $request): ?string
    {
        $accountSlug = $request->route('account_slug');
        if (! $accountSlug) {
            return null;
        }

        return $this->accountResolver->resolveAccountIdBySlug((string) $accountSlug);
    }

    private function resolveAuthenticatedUserId(Request $request): ?string
    {
        $user = $request->user();

        return $user ? (string) $user->getAuthIdentifier() : null;
    }
}
