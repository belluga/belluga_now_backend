<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileFormatterService;
use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Http\Api\v1\Requests\AccountProfileStoreRequest;
use App\Http\Api\v1\Requests\AccountProfileUpdateRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountProfilesController extends Controller
{
    public function __construct(
        private readonly AccountProfileManagementService $profileService,
        private readonly AccountProfileMediaService $mediaService,
        private readonly AccountProfileQueryService $profileQueryService,
        private readonly AccountProfileFormatterService $formatter,
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

    public function publicIndex(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', $request->get('page_size', 15)) ?: 15;
        $paginator = $this->profileQueryService->publicPaginate($request->query(), $perPage);

        return response()->json($paginator->toArray());
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

        $updated = $this->profileService->update($profile, $validated);
        $this->mediaService->applyUploads($request, $updated);

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
