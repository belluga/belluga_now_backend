<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileCandidateDiscoveryService;
use App\Application\AccountProfiles\AccountProfileFormatterService;
use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\AccountProfiles\AccountProfileNestedGroupService;
use App\Application\AccountProfiles\AccountProfileNestedPublicMembersProjectionService;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Application\RuntimeDiscoveryFilterCatalogService;
use App\Http\Api\v1\Requests\AccountProfileCandidatesRequest;
use App\Http\Api\v1\Requests\AccountProfileNearRequest;
use App\Http\Api\v1\Requests\AccountProfileNestedGroupMembersPatchRequest;
use App\Http\Api\v1\Requests\AccountProfileNestedGroupMembersRequest;
use App\Http\Api\v1\Requests\AccountProfilePublicIndexRequest;
use App\Http\Api\v1\Requests\AccountProfilePublicNestedGroupMembersRequest;
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
        private readonly AccountProfileNestedGroupService $nestedGroupService,
        private readonly AccountProfileNestedPublicMembersProjectionService $nestedPublicMembersProjectionService,
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
        $validated['search'] = $request->normalizedSearch();

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

    public function publicNestedGroupMembers(
        AccountProfilePublicNestedGroupMembersRequest $request,
        string $tenant_domain,
        string $account_profile_slug,
        string $group_id,
    ): JsonResponse {
        return response()->json($this->nestedPublicMembersProjectionService->publicMemberPage(
            $account_profile_slug,
            $group_id,
            $request->perPage(),
            $request->suppliedPerPage(),
            $request->cursor(),
        ));
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

        $mediaFingerprint = $this->mediaService->mutationFingerprint($request);
        $mediaBackup = null;
        $profile = $this->profileService->create(
            $validated,
            $request->header('X-Request-Id'),
            $mediaFingerprint === []
                ? null
                : function (\App\Models\Tenants\AccountProfile $persistedProfile) use ($request, &$mediaBackup): array {
                    $mediaBackup ??= $this->mediaService->captureMutationBackup($request, $persistedProfile);

                    return $this->mediaService->applyUploads($request, $persistedProfile);
                },
            ['media' => $mediaFingerprint],
            static function () use (&$mediaBackup): mixed {
                if ($mediaBackup !== null) {
                    return $mediaBackup->restore();
                }

                return null;
            },
        );

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

    public function nestedGroupMembers(
        AccountProfileNestedGroupMembersRequest $request,
        string $tenant_domain,
        string $account_profile_id,
        string $group_id,
    ): JsonResponse {
        $profile = $this->profileQueryService->findOrFail($account_profile_id);

        return response()->json($this->nestedGroupService->adminMemberPage(
            $profile,
            $group_id,
            $request->perPage(),
            $request->suppliedPerPage(),
            $request->cursor(),
            $this->candidateDiscoveryService,
        ));
    }

    public function patchNestedGroupMembers(
        AccountProfileNestedGroupMembersPatchRequest $request,
        string $tenant_domain,
        string $account_profile_id,
        string $group_id,
    ): JsonResponse {
        $profile = $this->profileQueryService->findOrFail($account_profile_id);

        return response()->json([
            'data' => $this->profileService->patchNestedGroupMembers(
                $profile,
                $group_id,
                $request->aggregateRevision(),
                $request->addIds(),
                $request->removeIds(),
                $request->header('X-Request-Id'),
            ),
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

        $mediaFingerprint = $this->mediaService->mutationFingerprint($request);
        $mediaBackup = $this->mediaService->captureMutationBackup($request, $profile);

        $updated = $this->profileService->update(
            $profile,
            $validated,
            commandId: $request->header('X-Request-Id'),
            mutateWithinTransaction: $mediaFingerprint === []
                ? null
                : fn (\App\Models\Tenants\AccountProfile $persistedProfile): array => $this->mediaService->applyUploads($request, $persistedProfile),
            fingerprintSupplement: ['media' => $mediaFingerprint],
            compensateKnownRollback: $mediaBackup === null
                ? null
                : static fn (): mixed => $mediaBackup->restore(),
        );

        return response()->json([
            'data' => $this->formatter->format($updated),
        ]);
    }

    public function destroy(string $tenant_domain, string $account_profile_id): JsonResponse
    {
        $profile = $this->profileQueryService->findOrFail($account_profile_id);
        $this->profileService->delete($profile, request()->header('X-Request-Id'));

        return response()->json();
    }

    public function restore(string $tenant_domain, string $account_profile_id): JsonResponse
    {
        $profile = $this->profileQueryService->findOrFail($account_profile_id, true);
        $restored = $this->profileService->restore($profile, request()->header('X-Request-Id'));

        return response()->json([
            'data' => $this->formatter->format($restored),
        ]);
    }

    public function forceDestroy(string $tenant_domain, string $account_profile_id): JsonResponse
    {
        $profile = $this->profileQueryService->findOrFail($account_profile_id, true);
        $this->profileService->forceDelete($profile, request()->header('X-Request-Id'));

        return response()->json();
    }
}
