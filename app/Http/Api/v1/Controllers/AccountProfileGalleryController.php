<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileGalleryMutationService;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Http\Controllers\Controller;
use App\Models\Tenants\AccountProfile;
use App\Support\Validation\InputConstraints;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AccountProfileGalleryController extends Controller
{
    public function __construct(private readonly AccountProfileQueryService $profiles, private readonly AccountProfileGalleryMutationService $gallery) {}

    public function createGroup(Request $request, string $tenant_domain, string $account_profile_id): JsonResponse
    {
        $data = $request->validate(['subtitle' => ['required', 'string', 'max:255']]);
        $profile = $this->profiles->findOrFail($account_profile_id);

        return $this->respond($this->gallery->createGroup($profile, trim($data['subtitle']), $this->auditAttributes($request)), $profile);
    }

    public function updateGroup(Request $request, string $tenant_domain, string $account_profile_id, string $group_id): JsonResponse
    {
        $data = $request->validate(['subtitle' => ['required', 'string', 'max:255']]);
        $profile = $this->profiles->findOrFail($account_profile_id);

        return $this->respond($this->gallery->renameGroup($profile, $group_id, trim($data['subtitle']), $this->auditAttributes($request)), $profile);
    }

    public function deleteGroup(Request $request, string $tenant_domain, string $account_profile_id, string $group_id): JsonResponse
    {
        $profile = $this->profiles->findOrFail($account_profile_id);

        return $this->respond($this->gallery->deleteGroup($profile, $group_id, $request->getSchemeAndHttpHost(), $this->auditAttributes($request)), $profile);
    }

    public function reorderGroups(Request $request, string $tenant_domain, string $account_profile_id): JsonResponse
    {
        $data = $request->validate(['group_ids' => ['required', 'array'], 'group_ids.*' => ['required', 'string']]);
        $profile = $this->profiles->findOrFail($account_profile_id);

        return $this->respond($this->gallery->reorderGroups($profile, $data['group_ids'], $this->auditAttributes($request)), $profile);
    }

    public function createItem(Request $request, string $tenant_domain, string $account_profile_id, string $group_id): JsonResponse
    {
        $profile = $this->profiles->findOrFail($account_profile_id);

        return $this->respond($this->gallery->createItem($profile, $group_id, $this->itemInput($request, true), $request->getSchemeAndHttpHost(), $this->auditAttributes($request)), $profile);
    }

    public function updateItem(Request $request, string $tenant_domain, string $account_profile_id, string $group_id, string $item_id): JsonResponse
    {
        $profile = $this->profiles->findOrFail($account_profile_id);

        return $this->respond($this->gallery->updateItem($profile, $group_id, $item_id, $this->itemInput($request, false), $request->getSchemeAndHttpHost(), $this->auditAttributes($request)), $profile);
    }

    public function deleteItem(Request $request, string $tenant_domain, string $account_profile_id, string $group_id, string $item_id): JsonResponse
    {
        $profile = $this->profiles->findOrFail($account_profile_id);

        return $this->respond($this->gallery->deleteItem($profile, $group_id, $item_id, $request->getSchemeAndHttpHost(), $this->auditAttributes($request)), $profile);
    }

    public function reorderItems(Request $request, string $tenant_domain, string $account_profile_id, string $group_id): JsonResponse
    {
        $data = $request->validate(['item_ids' => ['required', 'array'], 'item_ids.*' => ['required', 'string']]);
        $profile = $this->profiles->findOrFail($account_profile_id);

        return $this->respond($this->gallery->reorderItems($profile, $group_id, $data['item_ids'], $this->auditAttributes($request)), $profile);
    }

    /** @return array<string,mixed> */
    private function itemInput(Request $request, bool $create): array
    {
        $data = $request->validate(['type' => [$create ? 'required' : 'sometimes', 'string', 'in:photo,youtube'], 'title' => ['sometimes', 'nullable', 'string', 'max:255'], 'description' => ['sometimes', 'nullable', 'string', 'max:2000'], 'image' => ['sometimes', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.InputConstraints::IMAGE_MAX_KB], 'youtube_url' => ['sometimes', 'nullable', 'string', 'max:2048']]);
        $type = $data['type'] ?? null;
        if ($type === 'photo' && array_key_exists('youtube_url', $data)) {
            throw ValidationException::withMessages(['youtube_url' => ['Photo items cannot include a YouTube URL.']]);
        }
        if ($type === 'youtube' && $request->hasFile('image')) {
            throw ValidationException::withMessages(['image' => ['YouTube items cannot include an image.']]);
        }
        if ($create && (($data['type'] ?? null) === 'photo') && ! $request->hasFile('image')) {
            throw ValidationException::withMessages(['image' => ['An image is required for photo items.']]);
        }
        if ($create && (($data['type'] ?? null) === 'youtube') && ! array_key_exists('youtube_url', $data)) {
            throw ValidationException::withMessages(['youtube_url' => ['A YouTube URL is required for YouTube items.']]);
        }
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image');
        }

        return $data;
    }

    /** @param array<int,array<string,mixed>> $groups */
    private function respond(array $groups, AccountProfile $profile): JsonResponse
    {
        return response()->json(['data' => ['gallery_groups' => $groups, 'gallery_capabilities' => $this->gallery->capabilities($profile)]]);
    }

    /** @return array{updated_by?:string,updated_by_type?:string} */
    private function auditAttributes(Request $request): array
    {
        $actor = $request->user();
        if ($actor === null) {
            return [];
        }

        return [
            'updated_by' => (string) $actor->_id,
            'updated_by_type' => $actor instanceof \App\Models\Landlord\LandlordUser ? 'landlord' : 'tenant',
        ];
    }
}
