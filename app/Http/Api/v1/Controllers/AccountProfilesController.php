<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileCandidateDiscoveryService;
use App\Application\AccountProfiles\AccountProfileFormatterService;
use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Application\RuntimeDiscoveryFilterCatalogService;
use App\Http\Api\v1\Requests\AccountProfileCandidatesRequest;
use App\Http\Api\v1\Requests\AccountProfileNearRequest;
use App\Http\Api\v1\Requests\AccountProfilePublicIndexRequest;
use App\Http\Api\v1\Requests\AccountProfileStoreRequest;
use App\Http\Api\v1\Requests\AccountProfileUpdateRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountProfilesController extends Controller
{
    public function __construct(
        private readonly AccountProfileManagementService $profileService,
        private readonly AccountProfileCandidateDiscoveryService $candidateDiscoveryService,
        private readonly AccountProfileMediaService $mediaService,
        private readonly AccountProfileQueryService $profileQueryService,
        private readonly AccountProfileFormatterService $formatter,
        private readonly RuntimeDiscoveryFilterCatalogService $runtimeDiscoveryFilterCatalogService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', $request->get('page_size', 15)) ?: 15;

        $paginator = $this->profileQueryService->paginate(
            $request->query(),
            $request->boolean('archived'),
            $perPage
        );

        return response()->json($paginator->toArray());
    }

    public function candidates(AccountProfileCandidatesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json($this->candidateDiscoveryService->page(
            $validated['scope'],
            $request->normalizedSearch(),
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 20),
            $validated['exclude_account_profile_id'] ?? null,
        ));
    }

    public function publicIndex(AccountProfilePublicIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $perPage = (int) ($validated['per_page'] ?? $validated['page_size'] ?? 15);
        $payload = $this->profileQueryService->publicPageEnvelope($validated, $perPage);
        $payload['discovery_filter_catalog'] = $this->runtimeDiscoveryFilterCatalogService
            ->buildCanonicalCatalog(
                'discovery.account_profiles',
                is_array($payload['discovery_filter_facets'] ?? null)
                    ? $payload['discovery_filter_facets']
                    : null,
                $request->getSchemeAndHttpHost()
            );

        return response()->json($payload);
    }

    public function publicNear(AccountProfileNearRequest $request): JsonResponse
    {
        return response()->json(
            $this->profileQueryService->publicNear($request->validated())
        );
    }

    public function publicShowBySlug(string $tenant_domain, string $account_profile_slug): JsonResponse
    {
        $profile = $this->profileQueryService->publicFindBySlugOrFail($account_profile_slug);

        return response()->json([
            'data' => $this->formatter->format(
                $profile,
                includeAgendaOccurrences: true,
                publicContactProjection: true,
            ),
        ]);
    }

    public function store(AccountProfileStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        unset($validated['avatar'], $validated['cover']);
        $actor = $request->user();

        if ($actor) {
            $validated['created_by'] = (string) $actor->_id;
            $validated['created_by_type'] = $actor instanceof \App\Models\Landlord\LandlordUser ? 'landlord' : 'tenant';
            $validated['updated_by'] = (string) $actor->_id;
            $validated['updated_by_type'] = $validated['created_by_type'];
        }

        $profile = $this->profileService->create($validated);
        $this->mediaService->applyUploads($request, $profile);

        return response()->json([
            'data' => $this->formatter->format($profile),
        ], 201);
    }

    public function show(string $tenant_domain, string $account_profile_id): JsonResponse
    {
        $profile = $this->profileQueryService->findOrFail($account_profile_id);

        return response()->json([
            'data' => $this->formatter->format($profile),
        ]);
    }

    public function update(AccountProfileUpdateRequest $request, string $tenant_domain, string $account_profile_id): JsonResponse
    {
        $profile = $this->profileQueryService->findOrFail($account_profile_id);

        $validated = $request->validated();
        unset($validated['avatar'], $validated['cover']);
        $actor = $request->user();
        if ($actor) {
            $validated['updated_by'] = (string) $actor->_id;
            $validated['updated_by_type'] = $actor instanceof \App\Models\Landlord\LandlordUser ? 'landlord' : 'tenant';
        }

        $hasMediaMutation = $request->hasFile('avatar')
            || $request->hasFile('cover')
            || $request->boolean('remove_avatar')
            || $request->boolean('remove_cover');

        $updated = $this->profileService->update(
            $profile,
            $validated,
            syncMapPoiProjection: ! $hasMediaMutation
        );
        $this->mediaService->applyUploads($request, $updated);
        if ($hasMediaMutation) {
            $this->profileService->syncMapPoiProjection($updated);
        }

        return response()->json([
            'data' => $this->formatter->format($updated),
        ]);
    }

    public function destroy(string $tenant_domain, string $account_profile_id): JsonResponse
    {
        $profile = $this->profileQueryService->findOrFail($account_profile_id);
        $this->profileService->delete($profile);

        return response()->json();
    }

    public function restore(string $tenant_domain, string $account_profile_id): JsonResponse
    {
        $profile = $this->profileQueryService->findOrFail($account_profile_id, true);
        $restored = $this->profileService->restore($profile);

        return response()->json([
            'data' => $this->formatter->format($restored),
        ]);
    }

    public function forceDestroy(string $tenant_domain, string $account_profile_id): JsonResponse
    {
        $profile = $this->profileQueryService->findOrFail($account_profile_id, true);
        $this->profileService->forceDelete($profile);

        return response()->json();
    }
}
