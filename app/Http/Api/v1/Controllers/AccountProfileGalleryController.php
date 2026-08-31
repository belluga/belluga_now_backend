<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileGalleryMutationService;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AccountProfileGalleryController extends Controller
{
    public function __construct(private readonly AccountProfileQueryService $profiles, private readonly AccountProfileGalleryMutationService $gallery) {}

    public function createGroup(Request $request, string $tenant_domain, string $account_profile_id): JsonResponse
    {
        $data = $request->validate(['subtitle' => ['required', 'string', 'max:255']]);

        return $this->respond($this->gallery->createGroup($this->profiles->findOrFail($account_profile_id), trim($data['subtitle'])));
    }

    public function updateGroup(Request $request, string $tenant_domain, string $account_profile_id, string $group_id): JsonResponse
    {
        $data = $request->validate(['subtitle' => ['required', 'string', 'max:255']]);

        return $this->respond($this->gallery->renameGroup($this->profiles->findOrFail($account_profile_id), $group_id, trim($data['subtitle'])));
    }

    public function deleteGroup(Request $request, string $tenant_domain, string $account_profile_id, string $group_id): JsonResponse
    {
        return $this->respond($this->gallery->deleteGroup($this->profiles->findOrFail($account_profile_id), $group_id, $request->getSchemeAndHttpHost()));
    }

    public function reorderGroups(Request $request, string $tenant_domain, string $account_profile_id): JsonResponse
    {
        $data = $request->validate(['group_ids' => ['required', 'array'], 'group_ids.*' => ['required', 'string']]);

        return $this->respond($this->gallery->reorderGroups($this->profiles->findOrFail($account_profile_id), $data['group_ids']));
    }

    public function createItem(Request $request, string $tenant_domain, string $account_profile_id, string $group_id): JsonResponse
    {
        return $this->respond($this->gallery->createItem($this->profiles->findOrFail($account_profile_id), $group_id, $this->itemInput($request, true), $request->getSchemeAndHttpHost()));
    }

    public function updateItem(Request $request, string $tenant_domain, string $account_profile_id, string $group_id, string $item_id): JsonResponse
    {
        return $this->respond($this->gallery->updateItem($this->profiles->findOrFail($account_profile_id), $group_id, $item_id, $this->itemInput($request, false), $request->getSchemeAndHttpHost()));
    }

    public function deleteItem(Request $request, string $tenant_domain, string $account_profile_id, string $group_id, string $item_id): JsonResponse
    {
        return $this->respond($this->gallery->deleteItem($this->profiles->findOrFail($account_profile_id), $group_id, $item_id, $request->getSchemeAndHttpHost()));
    }

    public function reorderItems(Request $request, string $tenant_domain, string $account_profile_id, string $group_id): JsonResponse
    {
        $data = $request->validate(['item_ids' => ['required', 'array'], 'item_ids.*' => ['required', 'string']]);

        return $this->respond($this->gallery->reorderItems($this->profiles->findOrFail($account_profile_id), $group_id, $data['item_ids']));
    }

    /** @return array<string,mixed> */
    private function itemInput(Request $request, bool $create): array
    {
        $data = $request->validate(['type' => [$create ? 'required' : 'sometimes', 'string', 'in:photo,youtube'], 'description' => ['sometimes', 'nullable', 'string', 'max:2000'], 'image' => ['sometimes', 'file', 'image'], 'youtube_url' => ['sometimes', 'nullable', 'string']]);
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
    private function respond(array $groups): JsonResponse
    {
        return response()->json(['data' => ['gallery_groups' => $groups, 'gallery_capabilities' => $this->gallery->capabilities()]]);
    }
}
