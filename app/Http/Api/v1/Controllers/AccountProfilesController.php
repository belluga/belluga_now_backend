<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileGeoQueryService;
use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileOwnershipService;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Http\Api\v1\Requests\AccountProfileStoreRequest;
use App\Http\Api\v1\Requests\AccountProfileUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountProfilesController extends Controller
{
    public function __construct(
        private readonly AccountProfileManagementService $profileService,
        private readonly AccountProfileQueryService $profileQueryService,
        private readonly AccountProfileGeoQueryService $geoQueryService,
        private readonly AccountProfileOwnershipService $ownershipService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 15) ?: 15;

        $paginator = $this->profileQueryService->paginate(
            $request->query(),
            $request->boolean('archived'),
            $perPage
        );

        return response()->json($paginator->toArray());
    }

    public function store(AccountProfileStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $actor = $request->user();

        if ($actor) {
            $validated['created_by'] = (string) $actor->_id;
            $validated['created_by_type'] = $actor instanceof \App\Models\Landlord\LandlordUser ? 'landlord' : 'tenant';
            $validated['updated_by'] = (string) $actor->_id;
            $validated['updated_by_type'] = $validated['created_by_type'];
        }

        $profile = $this->profileService->create($validated);

        return response()->json([
            'data' => $this->formatProfile($profile),
        ], 201);
    }

    public function show(string $account_profile_id): JsonResponse
    {
        $profile = AccountProfile::query()->where('_id', $account_profile_id)->firstOrFail();

        return response()->json([
            'data' => $this->formatProfile($profile),
        ]);
    }

    public function update(AccountProfileUpdateRequest $request, string $account_profile_id): JsonResponse
    {
        $profile = AccountProfile::query()->where('_id', $account_profile_id)->firstOrFail();

        $validated = $request->validated();
        $actor = $request->user();
        if ($actor) {
            $validated['updated_by'] = (string) $actor->_id;
            $validated['updated_by_type'] = $actor instanceof \App\Models\Landlord\LandlordUser ? 'landlord' : 'tenant';
        }

        $updated = $this->profileService->update($profile, $validated);

        return response()->json([
            'data' => $this->formatProfile($updated),
        ]);
    }

    public function destroy(string $account_profile_id): JsonResponse
    {
        $profile = AccountProfile::query()->where('_id', $account_profile_id)->firstOrFail();
        $this->profileService->delete($profile);

        return response()->json();
    }

    public function restore(string $account_profile_id): JsonResponse
    {
        $profile = AccountProfile::onlyTrashed()->where('_id', $account_profile_id)->firstOrFail();
        $restored = $this->profileService->restore($profile);

        return response()->json([
            'data' => $this->formatProfile($restored),
        ]);
    }

    public function forceDestroy(string $account_profile_id): JsonResponse
    {
        $profile = AccountProfile::onlyTrashed()->where('_id', $account_profile_id)->firstOrFail();
        $this->profileService->forceDelete($profile);

        return response()->json();
    }

    public function geoIndex(Request $request): JsonResponse
    {
        $results = $this->geoQueryService->search($request->query());

        return response()->json([
            'data' => $results,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatProfile(AccountProfile $profile): array
    {
        $account = Account::query()->where('_id', $profile->account_id)->first();

        return [
            'id' => (string) $profile->_id,
            'account_id' => (string) $profile->account_id,
            'profile_type' => $profile->profile_type,
            'display_name' => $profile->display_name,
            'slug' => $profile->slug,
            'avatar_url' => $profile->avatar_url,
            'cover_url' => $profile->cover_url,
            'bio' => $profile->bio,
            'taxonomy_terms' => $profile->taxonomy_terms ?? [],
            'location' => $this->formatLocation($profile->location),
            'ownership_state' => $account ? $this->ownershipService->deriveOwnershipState($account) : null,
            'created_at' => $profile->created_at?->toJSON(),
            'updated_at' => $profile->updated_at?->toJSON(),
            'deleted_at' => $profile->deleted_at?->toJSON(),
        ];
    }

    /**
     * @param mixed $location
     * @return array<string, float>|null
     */
    private function formatLocation(mixed $location): ?array
    {
        if (! is_array($location)) {
            return null;
        }

        $coordinates = $location['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        return [
            'lat' => (float) $coordinates[1],
            'lng' => (float) $coordinates[0],
        ];
    }
}
