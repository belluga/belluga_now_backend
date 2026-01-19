<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Events\EventManagementService;
use App\Application\Events\EventQueryService;
use App\Http\Api\v1\Requests\EventIndexRequest;
use App\Http\Api\v1\Requests\EventStoreRequest;
use App\Http\Api\v1\Requests\EventUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventsController extends Controller
{
    public function __construct(
        private readonly EventQueryService $eventQueryService,
        private readonly EventManagementService $eventManagementService
    ) {
    }

    public function index(EventIndexRequest $request): JsonResponse
    {
        $perPage = (int) ($request->get('page_size') ?? 15);
        $perPage = $perPage > 0 ? $perPage : 15;
        $search = trim((string) ($request->get('search') ?? ''));
        $status = $request->get('status');

        $query = Event::query();

        if ($request->boolean('archived')) {
            $query->onlyTrashed();
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', '%' . $search . '%')
                    ->orWhere('content', 'like', '%' . $search . '%')
                    ->orWhere('venue.display_name', 'like', '%' . $search . '%');
            });
        }

        if ($status !== null) {
            $query->where('publication.status', $status);
        }

        $paginator = $query
            ->orderBy('date_time_start', 'desc')
            ->paginate($perPage)
            ->through(fn (Event $event): array => $this->formatManagementEvent($event));

        return response()->json($paginator->toArray());
    }

    public function store(EventStoreRequest $request): JsonResponse
    {
        $event = $this->eventManagementService->create($request->validated());

        return response()->json([
            'data' => $this->formatManagementEvent($event),
        ], 201);
    }

    public function update(EventUpdateRequest $request, string $event_id): JsonResponse
    {
        $event = $this->eventQueryService->findByIdOrSlug($event_id);

        if (! $event) {
            abort(404, 'Event not found.');
        }

        $updated = $this->eventManagementService->update($event, $request->validated());

        return response()->json([
            'data' => $this->formatManagementEvent($updated),
        ]);
    }

    public function destroy(string $event_id): JsonResponse
    {
        $event = $this->eventQueryService->findByIdOrSlug($event_id);

        if (! $event) {
            abort(404, 'Event not found.');
        }

        $this->eventManagementService->delete($event);

        return response()->json();
    }

    public function show(Request $request, string $event_id): JsonResponse
    {
        $event = $this->eventQueryService->findByIdOrSlug($event_id);

        if (! $event) {
            abort(404, 'Event not found.');
        }

        $tenant = Tenant::resolve();

        return response()->json([
            'tenant_id' => (string) $tenant->_id,
            'data' => $this->eventQueryService->formatEvent($event, $request->user()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatManagementEvent(Event $event): array
    {
        $payload = $this->eventQueryService->formatEvent($event);

        $publication = $event->publication ?? null;
        $publication = is_array($publication) ? $publication : (array) $publication;
        $publishAt = $publication['publish_at'] ?? null;
        if ($publishAt instanceof \MongoDB\BSON\UTCDateTime) {
            $publishAt = $publishAt->toDateTime();
        }
        if ($publishAt instanceof \DateTimeInterface) {
            $publishAt = $publishAt->format(\DateTimeInterface::ATOM);
        }

        $payload['publication'] = [
            'status' => $publication['status'] ?? 'draft',
            'publish_at' => $publishAt,
        ];
        $payload['venue_id'] = $payload['venue']['id'] ?? null;
        $payload['artist_ids'] = array_values(array_filter(array_map(
            static fn ($artist): ?string => is_array($artist) ? (string) ($artist['id'] ?? '') : null,
            $payload['artists'] ?? []
        )));
        $payload['created_at'] = $event->created_at?->toJSON();
        $payload['updated_at'] = $event->updated_at?->toJSON();
        $payload['deleted_at'] = $event->deleted_at?->toJSON();

        return $payload;
    }
}
