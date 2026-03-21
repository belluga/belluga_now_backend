<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Controllers;

use Belluga\Events\Application\Events\EventManagementService;
use Belluga\Events\Application\Events\EventMediaService;
use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Contracts\EventAccountResolverContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventTemplateSnapshotReadContract;
use Belluga\Events\Contracts\EventTenantContextContract;
use Belluga\Events\Exceptions\EventNotPubliclyVisibleException;
use Belluga\Events\Http\Api\v1\Requests\EventIndexRequest;
use Belluga\Events\Http\Api\v1\Requests\EventPartyCandidatesRequest;
use Belluga\Events\Http\Api\v1\Requests\EventStoreRequest;
use Belluga\Events\Http\Api\v1\Requests\EventUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EventsController extends Controller
{
    public function __construct(
        private readonly EventQueryService $eventQueryService,
        private readonly EventManagementService $eventManagementService,
        private readonly EventAccountResolverContract $accountResolver,
        private readonly EventProfileResolverContract $profileResolver,
        private readonly EventTenantContextContract $tenantContext,
        private readonly EventTemplateSnapshotReadContract $eventTemplateRead,
        private readonly EventMediaService $eventMediaService,
    ) {}

    public function index(EventIndexRequest $request): JsonResponse
    {
        $perPage = (int) ($request->get('page_size') ?? 15);
        $perPage = $perPage > 0 ? $perPage : 15;
        $accountContextId = $this->resolveAccountFromRoute($request);
        $isAdmin = $this->isAdminContext($request);

        $paginator = $this->eventQueryService->paginateManagement(
            $request->query(),
            $request->boolean('archived'),
            $perPage,
            $isAdmin,
            $accountContextId
        );

        return response()->json($paginator->toArray());
    }

    public function partyCandidates(EventPartyCandidatesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $search = isset($validated['search']) ? trim((string) $validated['search']) : null;
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 50;

        $candidates = $this->profileResolver->listPartyCandidates($search, $limit);

        return response()->json([
            'data' => $candidates,
        ]);
    }

    public function store(EventStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        unset($validated['cover'], $validated['remove_cover']);
        $payload = $this->applyTemplateToPayload($validated);
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
        $updated = $this->eventManagementService->update($event, $this->applyTemplateToPayload($validated));
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyTemplateToPayload(array $payload): array
    {
        $templateId = (string) ($payload['template_id'] ?? '');
        if ($templateId === '') {
            return $payload;
        }

        $snapshot = $this->eventTemplateRead->findTemplateSnapshot($templateId);
        if (! is_array($snapshot)) {
            throw ValidationException::withMessages([
                'template_id' => ['Template not found or inactive.'],
            ]);
        }

        $defaults = is_array($snapshot['defaults'] ?? null) ? $snapshot['defaults'] : [];
        $fieldStates = is_array($snapshot['field_states'] ?? null) ? $snapshot['field_states'] : [];
        $hiddenFields = is_array($snapshot['hidden_fields'] ?? null) ? $snapshot['hidden_fields'] : [];

        $guardedPaths = [];
        foreach ($fieldStates as $path => $state) {
            if (in_array((string) $state, ['hidden', 'disabled'], true)) {
                $guardedPaths[] = (string) $path;
            }
        }
        foreach ($hiddenFields as $path) {
            if (is_string($path) && $path !== '') {
                $guardedPaths[] = $path;
            }
        }

        foreach (array_values(array_unique($guardedPaths)) as $path) {
            if (! Arr::has($payload, $path)) {
                continue;
            }

            if (! Arr::has($defaults, $path)) {
                throw ValidationException::withMessages([
                    $path => ['Field cannot be provided by payload for this template.'],
                ]);
            }

            if (Arr::get($payload, $path) !== Arr::get($defaults, $path)) {
                throw ValidationException::withMessages([
                    $path => ['Template-protected field override is not allowed.'],
                ]);
            }
        }

        foreach ($defaults as $path => $value) {
            $state = (string) ($fieldStates[$path] ?? 'enabled');
            $shouldForce = in_array($state, ['hidden', 'disabled'], true);
            if ($shouldForce || ! Arr::has($payload, (string) $path)) {
                Arr::set($payload, (string) $path, $value);
            }
        }

        unset($payload['template_id'], $payload['template_version']);

        $existingTicketing = is_array($payload['ticketing'] ?? null) ? $payload['ticketing'] : [];
        $existingTicketing['template'] = [
            'template_id' => (string) ($snapshot['template_id'] ?? $templateId),
            'version' => (int) ($snapshot['version'] ?? 1),
        ];
        $payload['ticketing'] = $existingTicketing;

        return $payload;
    }
}
