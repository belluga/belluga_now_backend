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
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

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
        $accountContext = $this->resolveAccountFromRoute($request);
        $accountId = $accountContext ? (string) $accountContext->_id : (string) ($request->get('account_id') ?? '');
        $accountProfileId = (string) ($request->get('account_profile_id') ?? '');

        if ($request->boolean('archived') && $this->isAdminContext($request)) {
            $query->onlyTrashed();
        }

        if ($accountContext) {
            $this->applyAccountFiltersToQuery($query, (string) $accountContext->_id, '');
        } elseif ($accountId !== '' || $accountProfileId !== '') {
            $this->applyAccountFiltersToQuery($query, $accountId, $accountProfileId);
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

        if (! $this->isAdminContext($request)) {
            $this->applyPublicPublicationFilter($query);
        }

        $paginator = $query
            ->orderBy('date_time_start', 'desc')
            ->paginate($perPage)
            ->through(fn (Event $event): array => $this->formatManagementEvent($event));

        return response()->json($paginator->toArray());
    }

    public function store(EventStoreRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $account = $this->resolveAccountFromRoute($request);

        if ($account) {
            $payload['account_id'] = (string) $account->_id;
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
            'data' => $this->formatManagementEvent($event),
        ], 201);
    }

    public function update(EventUpdateRequest $request, string $event_id): JsonResponse
    {
        $event = $this->eventQueryService->findByIdOrSlug($event_id);

        if (! $event) {
            abort(404, 'Event not found.');
        }

        $account = $this->resolveAccountFromRoute($request);
        if ($account && ! $this->eventBelongsToAccount($event, $account)) {
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

        $account = $this->resolveAccountFromRoute(request());
        if ($account && ! $this->eventBelongsToAccount($event, $account)) {
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

        $account = $this->resolveAccountFromRoute($request);
        if ($account && ! $this->eventBelongsToAccount($event, $account)) {
            abort(404, 'Event not found.');
        }

        if (! $this->isAdminContext($request)) {
            $this->assertPublicVisible($event);
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

    private function isAdminContext(Request $request): bool
    {
        if ($request->route('account_slug')) {
            return true;
        }

        return str_starts_with($request->path(), 'admin/api/v1');
    }

    private function resolveAccountFromRoute(Request $request): ?Account
    {
        $accountSlug = $request->route('account_slug');
        if (! $accountSlug) {
            return null;
        }

        $account = Account::query()->where('slug', $accountSlug)->first();
        if (! $account) {
            abort(404, 'Account not found.');
        }

        return $account;
    }

    private function applyAccountFiltersToQuery($query, string $accountId, string $accountProfileId): void
    {
        if ($accountProfileId !== '') {
            $query->where(function ($builder) use ($accountProfileId): void {
                $builder->where('account_profile_id', $accountProfileId)
                    ->orWhere('venue.id', $accountProfileId);
            });

            return;
        }

        if ($accountId === '') {
            return;
        }

        $profileIds = AccountProfile::query()
            ->where('account_id', $accountId)
            ->pluck('_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();

        $query->where(function ($builder) use ($accountId, $profileIds): void {
            $builder->where('account_id', $accountId);
            if ($profileIds !== []) {
                $builder->orWhereIn('venue.id', $profileIds);
            }
        });
    }

    private function eventBelongsToAccount(Event $event, Account $account): bool
    {
        $accountId = (string) $account->_id;

        if ((string) ($event->account_id ?? '') === $accountId) {
            return true;
        }

        $profileId = $event->account_profile_id
            ?? ($event->venue['id'] ?? null);

        if (! $profileId) {
            return false;
        }

        return AccountProfile::query()
            ->where('_id', (string) $profileId)
            ->where('account_id', $accountId)
            ->exists();
    }

    private function applyPublicPublicationFilter($query): void
    {
        $now = Carbon::now();

        $query->where(function ($builder) {
            $builder->where('publication.status', 'published')
                ->orWhereNull('publication.status');
        });

        $query->where(function ($builder) use ($now) {
            $builder->whereNull('publication.publish_at')
                ->orWhere('publication.publish_at', '<=', $now);
        });
    }

    private function assertPublicVisible(Event $event): void
    {
        $publication = $event->publication ?? [];
        $publication = is_array($publication) ? $publication : (array) $publication;
        $status = (string) ($publication['status'] ?? 'published');
        $publishAt = $publication['publish_at'] ?? null;

        if ($status !== 'published') {
            abort(404, 'Event not found.');
        }

        if ($publishAt instanceof \MongoDB\BSON\UTCDateTime) {
            $publishAt = $publishAt->toDateTime();
        }

        if ($publishAt instanceof \DateTimeInterface && $publishAt > Carbon::now()) {
            abort(404, 'Event not found.');
        }
    }
}
