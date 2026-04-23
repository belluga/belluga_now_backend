<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Controllers;

use Belluga\Events\Application\Events\EventManagementService;
use Belluga\Events\Application\Events\EventMediaService;
use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Application\Events\LegacyEventPartiesCanonicalizationService;
use Belluga\Events\Contracts\EventAccountResolverContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventTenantContextContract;
use Belluga\Events\Exceptions\EventNotPubliclyVisibleException;
use Belluga\Events\Http\Api\v1\Requests\EventAccountProfileCandidatesRequest;
use Belluga\Events\Http\Api\v1\Requests\EventIndexRequest;
use Belluga\Events\Http\Api\v1\Requests\EventStoreRequest;
use Belluga\Events\Http\Api\v1\Requests\EventUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EventsController extends Controller
{
    public function __construct(
        private readonly EventQueryService $eventQueryService,
        private readonly EventManagementService $eventManagementService,
        private readonly EventAccountResolverContract $accountResolver,
        private readonly EventProfileResolverContract $profileResolver,
        private readonly EventTenantContextContract $tenantContext,
        private readonly EventMediaService $eventMediaService,
        private readonly LegacyEventPartiesCanonicalizationService $legacyEventPartiesCanonicalizationService,
    ) {}

    public function index(EventIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $perPage = isset($validated['page_size']) ? (int) $validated['page_size'] : 15;
        $perPage = max(1, min($perPage, 100));
        $accountContextId = $this->resolveAccountFromRoute($request);
        $isAdmin = $this->isAdminContext($request);

        $paginator = $this->eventQueryService->paginateManagement(
            $validated,
            $request->boolean('archived'),
            $perPage,
            $isAdmin,
            $accountContextId
        );

        return response()->json($paginator->toArray());
    }

    public function accountProfileCandidates(EventAccountProfileCandidatesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $candidateType = trim((string) ($validated['type'] ?? ''));
        $search = isset($validated['search']) ? trim((string) $validated['search']) : null;
        $page = isset($validated['page']) ? (int) $validated['page'] : 1;
        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : (isset($validated['page_size']) ? (int) $validated['page_size'] : 15);
        $accountContextId = $candidateType === 'physical_host'
            ? $this->resolveAccountFromRoute($request)
            : null;

        $candidates = $this->profileResolver->paginateAccountProfileCandidates(
            $candidateType,
            $search,
            $page,
            $perPage,
            $accountContextId
        );

        return response()->json($candidates->toArray());
    }

    public function legacyEventPartiesSummary(): JsonResponse
    {
        return response()->json([
            'data' => $this->legacyEventPartiesCanonicalizationService->inspect(),
        ]);
    }

    public function repairLegacyEventParties(): JsonResponse
    {
        return response()->json([
            'data' => $this->legacyEventPartiesCanonicalizationService->repair(),
        ]);
    }

    public function store(EventStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        unset($validated['cover'], $validated['remove_cover']);
        $payload = $validated;
        $payload['_created_by'] = $this->resolveActorPrincipal($request);
        $accountIdFromRoute = $this->resolveAccountFromRoute($request);

        if ($accountIdFromRoute) {
            $payload['_account_context_id'] = $accountIdFromRoute;
        }

        $event = $this->eventManagementService->create($payload);
        $this->eventMediaService->applyUploads($request, $event);

        return response()->json([
            'data' => $this->eventQueryService->formatManagementEvent($event->fresh()),
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
        if ($accountId && ! $this->eventQueryService->eventEditableByAccount($event, $accountId, $this->resolveAuthenticatedUserId($request))) {
            abort(404, 'Event not found.');
        }

        $validated = $request->validated();
        unset($validated['cover'], $validated['remove_cover']);
        $updated = $this->eventManagementService->update($event, $validated);
        $this->eventMediaService->applyUploads($request, $updated);

        return response()->json([
            'data' => $this->eventQueryService->formatManagementEvent($updated->fresh()),
        ]);
    }

    public function destroy(Request $request, string $event_id): JsonResponse
    {
        $eventId = (string) ($request->route('event_id') ?? $event_id);
        $event = $this->eventQueryService->findByIdOrSlug($eventId);

        if (! $event) {
            abort(404, 'Event not found.');
        }

        $accountId = $this->resolveAccountFromRoute($request);
        if ($accountId && ! $this->eventQueryService->eventEditableByAccount($event, $accountId, $this->resolveAuthenticatedUserId($request))) {
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
            try {
                $this->eventQueryService->assertPublicVisible($event);
            } catch (EventNotPubliclyVisibleException) {
                abort(404, 'Event not found.');
            }
        }

        $payload = $this->isAdminContext($request)
            ? $this->eventQueryService->formatManagementEvent($event)
            : $this->eventQueryService->formatEventDetail(
                $event,
                $this->resolveAuthenticatedUserId($request),
                is_string($request->query('occurrence')) ? $request->query('occurrence') : $eventId
            );

        return response()->json([
            'tenant_id' => $this->tenantContext->resolveCurrentTenantId(),
            'data' => $payload,
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

    /**
     * @return array{type: string, id: string}
     */
    private function resolveActorPrincipal(Request $request): array
    {
        $user = $request->user();
        $actorId = $user ? (string) $user->getAuthIdentifier() : '';

        if ($actorId === '') {
            return [
                'type' => 'system',
                'id' => 'system',
            ];
        }

        if ($request->route('account_slug')) {
            return [
                'type' => 'account_user',
                'id' => $actorId,
            ];
        }

        if (str_starts_with($request->path(), 'admin/api/v1')) {
            return [
                'type' => 'landlord_user',
                'id' => $actorId,
            ];
        }

        return [
            'type' => 'user',
            'id' => $actorId,
        ];
    }
}
