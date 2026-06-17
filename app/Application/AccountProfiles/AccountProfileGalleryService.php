<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use App\Support\Validation\InputConstraints;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

final class AccountProfileGalleryService
{
    public function __construct(
        private readonly AccountProfileMediaService $mediaService,
        private readonly AccountProfileTypeSetProvider $typeSetProvider,
    ) {}

    /**
     * @param  array<int, mixed>  $rawGroups
     * @param  array<string, mixed>  $actorContext
     */
    public function replace(
        AccountProfile $profile,
        array $rawGroups,
        Request $request,
        array $actorContext = [],
    ): AccountProfile {
        $baseUrl = $request->getSchemeAndHttpHost();
        $this->assertGalleryAllowed((string) ($profile->profile_type ?? ''));
        $existingItems = $this->existingItemsById($profile->gallery_groups ?? []);
        [$plannedGroups, $removedItemIds] = $this->planReplacement($rawGroups, $existingItems, $request);

        $persistedGroups = [];
        foreach ($plannedGroups as $groupOrder => $plannedGroup) {
            $persistedItems = [];
            foreach ($plannedGroup['items'] as $itemOrder => $plannedItem) {
                $mediaPath = $plannedItem['media_path'];
                $version = $plannedItem['version'];

                if ($plannedItem['upload_key'] !== null) {
                    $stored = $this->mediaService->storeGalleryUpload(
                        $baseUrl,
                        $profile,
                        $plannedItem['item_id'],
                        $plannedItem['upload_file'],
                    );
                    $mediaPath = $stored['media_path'];
                    $version = $stored['version'];
                }

                $persistedItems[] = [
                    'item_id' => $plannedItem['item_id'],
                    'description' => $plannedItem['description'],
                    'order' => $itemOrder,
                    'media_path' => $mediaPath,
                    'version' => $version,
                ];
            }

            $persistedGroups[] = [
                'group_id' => $plannedGroup['group_id'],
                'subtitle' => $plannedGroup['subtitle'],
                'order' => $groupOrder,
                'items' => $persistedItems,
            ];
        }

        $profile->gallery_groups = $persistedGroups;
        if (isset($actorContext['updated_by'])) {
            $profile->updated_by = $actorContext['updated_by'];
        }
        if (isset($actorContext['updated_by_type'])) {
            $profile->updated_by_type = $actorContext['updated_by_type'];
        }
        $profile->save();
        $profile->refresh();

        foreach ($removedItemIds as $itemId) {
            $this->mediaService->removeGalleryUpload($profile, $itemId, $baseUrl);
        }

        return $profile;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formatForRead(AccountProfile $profile, string $baseUrl): array
    {
        $rawGroups = $this->arrayFrom($profile->gallery_groups ?? []);
        $groups = [];

        foreach ($rawGroups as $groupIndex => $rawGroup) {
            if (! is_array($rawGroup)) {
                continue;
            }

            $groupId = trim((string) ($rawGroup['group_id'] ?? ''));
            $subtitle = trim((string) ($rawGroup['subtitle'] ?? ''));
            if ($groupId === '' || $subtitle === '') {
                continue;
            }

            $items = [];
            $rawItems = $this->arrayFrom($rawGroup['items'] ?? []);
            foreach ($rawItems as $itemIndex => $rawItem) {
                if (! is_array($rawItem)) {
                    continue;
                }

                $itemId = trim((string) ($rawItem['item_id'] ?? ''));
                if ($itemId === '') {
                    continue;
                }

                $version = $this->normalizeStoredVersion($rawItem['version'] ?? null);
                $items[] = [
                    '_source_index' => $itemIndex,
                    'item_id' => $itemId,
                    'description' => $this->normalizeNullableString($rawItem['description'] ?? null),
                    'order' => $this->normalizeOrder($rawItem['order'] ?? null, $itemIndex),
                    'image_url' => $this->mediaService->buildGalleryPublicUrl(
                        $baseUrl,
                        $profile,
                        $itemId,
                        $version,
                        $this->mediaService->defaultGalleryVariant(),
                    ),
                    'thumb_url' => $this->mediaService->buildGalleryPublicUrl(
                        $baseUrl,
                        $profile,
                        $itemId,
                        $version,
                        'thumb',
                    ),
                    'card_url' => $this->mediaService->buildGalleryPublicUrl(
                        $baseUrl,
                        $profile,
                        $itemId,
                        $version,
                        'card',
                    ),
                    'modal_url' => $this->mediaService->buildGalleryPublicUrl(
                        $baseUrl,
                        $profile,
                        $itemId,
                        $version,
                        'modal',
                    ),
                ];
            }

            if ($items === []) {
                continue;
            }

            usort(
                $items,
                static fn (array $left, array $right): int => [$left['order'], $left['_source_index']]
                    <=> [$right['order'], $right['_source_index']]
            );

            $groups[] = [
                '_source_index' => $groupIndex,
                'group_id' => $groupId,
                'subtitle' => $subtitle,
                'order' => $this->normalizeOrder($rawGroup['order'] ?? null, $groupIndex),
                'items' => array_values(array_map(
                    static fn (array $item, int $order): array => [
                        'item_id' => $item['item_id'],
                        'description' => $item['description'],
                        'order' => $order,
                        'image_url' => $item['image_url'],
                        'thumb_url' => $item['thumb_url'],
                        'card_url' => $item['card_url'],
                        'modal_url' => $item['modal_url'],
                    ],
                    $items,
                    array_keys($items),
                )),
            ];
        }

        usort(
            $groups,
            static fn (array $left, array $right): int => [$left['order'], $left['_source_index']]
                <=> [$right['order'], $right['_source_index']]
        );

        return array_values(array_map(
            static fn (array $group, int $order): array => [
                'group_id' => $group['group_id'],
                'subtitle' => $group['subtitle'],
                'order' => $order,
                'items' => $group['items'],
            ],
            $groups,
            array_keys($groups),
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formatForPublicDetail(AccountProfile $profile, string $baseUrl): array
    {
        if (! $this->isExposedForProfile($profile)) {
            return [];
        }

        return $this->formatForRead($profile, $baseUrl);
    }

    public function isExposedForProfile(AccountProfile $profile): bool
    {
        $profileType = trim((string) ($profile->profile_type ?? ''));

        return $this->profileTypeAllowsGallery($profileType);
    }

    /**
     * @param  array<int, mixed>  $rawGroups
     * @param  array<string, array{media_path:string,version:string}>  $existingItems
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function planReplacement(array $rawGroups, array $existingItems, Request $request): array
    {
        if (count($rawGroups) > InputConstraints::ACCOUNT_PROFILE_GALLERY_GROUPS_MAX) {
            throw ValidationException::withMessages([
                'gallery_groups' => ['Gallery groups exceed the configured limit.'],
            ]);
        }

        $plannedGroups = [];
        $seenGroupIds = [];
        $seenItemIds = [];
        $totalItems = 0;

        foreach ($rawGroups as $groupIndex => $rawGroup) {
            if (! is_array($rawGroup)) {
                throw ValidationException::withMessages([
                    "gallery_groups.{$groupIndex}" => ['Gallery group must be an object.'],
                ]);
            }

            $subtitle = trim((string) ($rawGroup['subtitle'] ?? ''));
            if ($subtitle === '') {
                throw ValidationException::withMessages([
                    "gallery_groups.{$groupIndex}.subtitle" => ['Gallery group subtitle is required.'],
                ]);
            }

            $groupId = $this->normalizeKey(
                $rawGroup['group_id'] ?? null,
                "gallery_groups.{$groupIndex}.group_id",
                'Gallery group id is invalid.',
            );
            if (isset($seenGroupIds[$groupId])) {
                throw ValidationException::withMessages([
                    "gallery_groups.{$groupIndex}.group_id" => ['Gallery group ids must be unique.'],
                ]);
            }
            $seenGroupIds[$groupId] = true;

            $rawItems = $this->arrayFrom($rawGroup['items'] ?? []);
            if ($rawItems === []) {
                throw ValidationException::withMessages([
                    "gallery_groups.{$groupIndex}.items" => ['Gallery groups cannot be empty.'],
                ]);
            }

            $plannedItems = [];
            foreach ($rawItems as $itemIndex => $rawItem) {
                if (! is_array($rawItem)) {
                    throw ValidationException::withMessages([
                        "gallery_groups.{$groupIndex}.items.{$itemIndex}" => ['Gallery item must be an object.'],
                    ]);
                }

                $itemId = $this->normalizeKey(
                    $rawItem['item_id'] ?? null,
                    "gallery_groups.{$groupIndex}.items.{$itemIndex}.item_id",
                    'Gallery item id is invalid.',
                );
                if (isset($seenItemIds[$itemId])) {
                    throw ValidationException::withMessages([
                        "gallery_groups.{$groupIndex}.items.{$itemIndex}.item_id" => ['Gallery item ids must be unique.'],
                    ]);
                }
                $seenItemIds[$itemId] = true;
                $totalItems++;

                $uploadKey = $this->normalizeUploadKey($rawItem['upload'] ?? null);
                $existingItem = $existingItems[$itemId] ?? null;

                if ($uploadKey === null && $existingItem === null) {
                    throw ValidationException::withMessages([
                        "gallery_groups.{$groupIndex}.items.{$itemIndex}.upload" => ['New gallery items require an uploaded image.'],
                    ]);
                }

                $uploadFile = null;
                if ($uploadKey !== null) {
                    $uploadFile = $request->file($uploadKey);
                    $this->assertValidUpload(
                        $uploadKey,
                        $uploadFile,
                        "gallery_groups.{$groupIndex}.items.{$itemIndex}.upload"
                    );
                }

                $plannedItems[] = [
                    '_source_index' => $itemIndex,
                    'item_id' => $itemId,
                    'description' => $this->normalizeNullableString($rawItem['description'] ?? null),
                    'order' => $this->normalizeOrder($rawItem['order'] ?? null, $itemIndex),
                    'upload_key' => $uploadKey,
                    'upload_file' => $uploadFile,
                    'media_path' => $existingItem['media_path'] ?? null,
                    'version' => $existingItem['version'] ?? (string) time(),
                ];
            }

            $plannedGroups[] = [
                '_source_index' => $groupIndex,
                'group_id' => $groupId,
                'subtitle' => $subtitle,
                'order' => $this->normalizeOrder($rawGroup['order'] ?? null, $groupIndex),
                'items' => $plannedItems,
            ];
        }

        if ($totalItems > InputConstraints::ACCOUNT_PROFILE_GALLERY_ITEMS_MAX) {
            throw ValidationException::withMessages([
                'gallery_groups' => ['Gallery items exceed the configured limit.'],
            ]);
        }

        usort(
            $plannedGroups,
            static fn (array $left, array $right): int => [$left['order'], $left['_source_index']]
                <=> [$right['order'], $right['_source_index']]
        );

        foreach ($plannedGroups as &$plannedGroup) {
            usort(
                $plannedGroup['items'],
                static fn (array $left, array $right): int => [$left['order'], $left['_source_index']]
                    <=> [$right['order'], $right['_source_index']]
            );
        }
        unset($plannedGroup);

        return [
            $plannedGroups,
            array_values(array_diff(array_keys($existingItems), array_keys($seenItemIds))),
        ];
    }

    private function assertGalleryAllowed(string $profileType): void
    {
        if ($this->profileTypeAllowsGallery($profileType)) {
            return;
        }

        throw ValidationException::withMessages([
            'gallery_groups' => ['Gallery is not enabled for this profile type.'],
        ]);
    }

    private function profileTypeAllowsGallery(string $profileType): bool
    {
        return $this->typeSetProvider->hasGalleryEnabled($profileType);
    }

    /**
     * @return array<string, array{media_path:string,version:string}>
     */
    private function existingItemsById(mixed $rawGroups): array
    {
        $groups = $this->arrayFrom($rawGroups);
        $items = [];

        foreach ($groups as $rawGroup) {
            if (! is_array($rawGroup)) {
                continue;
            }

            foreach ($this->arrayFrom($rawGroup['items'] ?? []) as $rawItem) {
                if (! is_array($rawItem)) {
                    continue;
                }

                $itemId = trim((string) ($rawItem['item_id'] ?? ''));
                $mediaPath = trim((string) ($rawItem['media_path'] ?? ''));
                if ($itemId === '' || $mediaPath === '') {
                    continue;
                }

                $items[$itemId] = [
                    'media_path' => $mediaPath,
                    'version' => $this->normalizeStoredVersion(
                        $rawItem['version'] ?? $this->extractVersionFromMediaPath($rawItem['image_url'] ?? null)
                    ),
                ];
            }
        }

        return $items;
    }

    private function normalizeKey(
        mixed $rawValue,
        string $field,
        string $errorMessage,
    ): string {
        $value = trim((string) ($rawValue ?? ''));
        if ($value === '') {
            $value = Str::lower((string) Str::ulid());
        } else {
            $value = Str::lower($value);
        }

        if (
            strlen($value) > InputConstraints::ACCOUNT_PROFILE_GALLERY_KEY_MAX
            || ! preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $value)
        ) {
            throw ValidationException::withMessages([
                $field => [$errorMessage],
            ]);
        }

        return $value;
    }

    private function normalizeUploadKey(mixed $rawValue): ?string
    {
        $value = trim((string) ($rawValue ?? ''));
        if ($value === '') {
            return null;
        }

        return $value;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeStoredVersion(mixed $value): string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? (string) time() : $normalized;
    }

    private function normalizeOrder(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? max(0, (int) $value) : $fallback;
    }

    private function extractVersionFromMediaPath(mixed $value): ?string
    {
        $query = parse_url(trim((string) ($value ?? '')), PHP_URL_QUERY);
        if (! is_string($query) || trim($query) === '') {
            return null;
        }

        parse_str($query, $parameters);
        $version = $parameters['v'] ?? null;
        if (! is_scalar($version)) {
            return null;
        }

        $normalized = trim((string) $version);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<int, mixed>
     */
    private function arrayFrom(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof BSONArray || $value instanceof BSONDocument) {
            return $value->getArrayCopy();
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return [];
    }

    private function assertValidUpload(
        string $uploadKey,
        mixed $file,
        string $errorField,
    ): void {
        $validator = Validator::make(
            [$uploadKey => $file],
            [$uploadKey => 'required|image|mimes:jpg,jpeg,png,webp|max:'.InputConstraints::IMAGE_MAX_KB]
        );

        if ($validator->fails() || ! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                $errorField => ['Gallery item upload must be a valid image.'],
            ]);
        }
    }
}
