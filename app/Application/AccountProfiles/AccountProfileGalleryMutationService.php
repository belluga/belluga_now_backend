<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

/** Granular, last-write-wins gallery mutations. */
final class AccountProfileGalleryMutationService
{
    public function __construct(
        private readonly AccountProfileGalleryService $gallery,
        private readonly AccountProfileManagementService $profiles,
        private readonly AccountProfileMediaService $media,
        private readonly AccountProfileTypeSetProvider $types,
        private readonly YoutubeVideoMetadataResolver $youtubeMetadata,
    ) {}

    /** @return array{max_galleries:int,max_items_per_gallery:int} */
    public function capabilities(): array
    {
        return ['max_galleries' => max(0, (int) config('gallery.max_galleries', 6)), 'max_items_per_gallery' => max(0, (int) config('gallery.max_items_per_gallery', 12))];
    }

    public function isAllowed(AccountProfile $profile): bool
    {
        return $this->types->hasGalleryEnabled((string) $profile->profile_type);
    }

    /** @return array<int,array<string,mixed>> */
    public function createGroup(AccountProfile $profile, string $subtitle): array
    {
        return $this->change($profile, function (array &$groups) use ($subtitle): void {
            if (count($groups) >= $this->capabilities()['max_galleries']) {
                $this->fail('gallery_capabilities.max_galleries', 'Gallery capacity has been reached.');
            }
            $groups[] = ['group_id' => Str::lower((string) Str::ulid()), 'subtitle' => $subtitle, 'order' => count($groups), 'items' => []];
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function renameGroup(AccountProfile $profile, string $id, string $subtitle): array
    {
        return $this->change($profile, function (array &$groups) use ($id, $subtitle): void {
            $groups[$this->groupIndex($groups, $id)]['subtitle'] = $subtitle;
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function deleteGroup(AccountProfile $profile, string $id, string $baseUrl): array
    {
        return $this->change($profile, function (array &$groups) use ($id, $profile, $baseUrl): void {
            $index = $this->groupIndex($groups, $id);
            foreach ($this->array($groups[$index]['items'] ?? []) as $item) {
                if (($item['type'] ?? 'photo') === 'photo' && isset($item['item_id'])) {
                    $this->media->removeGalleryUpload($profile, (string) $item['item_id'], $baseUrl);
                }
            }
            array_splice($groups, $index, 1);
        }, function (array $groups) use ($id): array {
            $index = $this->groupIndex($groups, $id);

            return array_values(array_filter(array_map(
                static fn (mixed $item): string => is_array($item)
                    && ($item['type'] ?? 'photo') === 'photo'
                    ? (string) ($item['item_id'] ?? '')
                    : '',
                $this->array($groups[$index]['items'] ?? []),
            )));
        }, $baseUrl);
    }

    /** @return array<int,array<string,mixed>> */
    public function reorderGroups(AccountProfile $profile, array $ids): array
    {
        return $this->change($profile, fn (array &$groups): array => $groups = $this->reorder($groups, $ids, 'group_id'));
    }

    /** @param array<string,mixed> $input @return array<int,array<string,mixed>> */
    public function createItem(AccountProfile $profile, string $groupId, array $input, string $baseUrl): array
    {
        $type = $this->type($input['type'] ?? null);
        $itemId = Str::lower((string) Str::ulid());

        return $this->change($profile, function (array &$groups) use ($groupId, $input, $profile, $baseUrl, $type, $itemId): void {
            $group = $this->groupIndex($groups, $groupId);
            $items = $this->array($groups[$group]['items'] ?? []);
            if (count($items) >= $this->capabilities()['max_items_per_gallery']) {
                $this->fail('gallery_capabilities.max_items_per_gallery', 'Gallery item capacity has been reached.');
            }
            $item = ['item_id' => $itemId, 'type' => $type, 'title' => $this->nullable($input['title'] ?? null), 'description' => $this->nullable($input['description'] ?? null), 'order' => count($items)];
            if ($type === 'youtube') {
                $item['youtube_video_id'] = $this->youtube($input['youtube_url'] ?? null);
                $item['player_aspect_ratio'] = $this->youtubeMetadata->playerAspectRatio($item['youtube_video_id']);
            } else {
                $file = $input['image'] ?? null;
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    $this->fail('image', 'Gallery item image is required.');
                } $item += $this->media->storeGalleryUpload($baseUrl, $profile, $item['item_id'], $file);
            }
            $items[] = $item;
            $groups[$group]['items'] = $items;
        }, $type === 'photo' ? [$itemId] : [], $baseUrl);
    }

    /** @param array<string,mixed> $input @return array<int,array<string,mixed>> */
    public function updateItem(AccountProfile $profile, string $groupId, string $itemId, array $input, string $baseUrl): array
    {
        return $this->change($profile, function (array &$groups) use ($groupId, $itemId, $input, $profile, $baseUrl): void {
            $group = $this->groupIndex($groups, $groupId);
            $items = $this->array($groups[$group]['items'] ?? []);
            $index = $this->itemIndex($items, $itemId);
            $item = $items[$index];
            $type = $this->type($item['type'] ?? 'photo');
            if (array_key_exists('type', $input) && $this->type($input['type']) !== $type) {
                $this->fail('type', 'Gallery item type is immutable.');
            }
            if ($type === 'photo' && array_key_exists('youtube_url', $input)) {
                $this->fail('youtube_url', 'Photo items cannot include a YouTube URL.');
            }
            if ($type === 'youtube' && array_key_exists('image', $input)) {
                $this->fail('image', 'YouTube items cannot include an image.');
            }
            if (array_key_exists('description', $input)) {
                $item['description'] = $this->nullable($input['description']);
            }
            if (array_key_exists('title', $input)) {
                $item['title'] = $this->nullable($input['title']);
            }
            if ($type === 'youtube' && array_key_exists('youtube_url', $input)) {
                $item['youtube_video_id'] = $this->youtube($input['youtube_url']);
                $item['player_aspect_ratio'] = $this->youtubeMetadata->playerAspectRatio($item['youtube_video_id']);
            }
            if ($type === 'photo' && array_key_exists('image', $input)) {
                if (! $input['image'] instanceof UploadedFile || ! $input['image']->isValid()) {
                    $this->fail('image', 'Gallery item image is invalid.');
                }
                $this->media->removeGalleryUpload($profile, $itemId, $baseUrl);
                $item = array_replace(
                    $item,
                    $this->media->storeGalleryUpload($baseUrl, $profile, $itemId, $input['image']),
                );
            }
            $items[$index] = $item;
            $groups[$group]['items'] = $items;
        }, array_key_exists('image', $input) ? [$itemId] : [], $baseUrl);
    }

    /** @return array<int,array<string,mixed>> */
    public function deleteItem(AccountProfile $profile, string $groupId, string $itemId, string $baseUrl): array
    {
        return $this->change($profile, function (array &$groups) use ($groupId, $itemId, $profile, $baseUrl): void {
            $group = $this->groupIndex($groups, $groupId);
            $items = $this->array($groups[$group]['items'] ?? []);
            $index = $this->itemIndex($items, $itemId);
            if (($items[$index]['type'] ?? 'photo') === 'photo') {
                $this->media->removeGalleryUpload($profile, $itemId, $baseUrl);
            } array_splice($items, $index, 1);
            $groups[$group]['items'] = $items;
        }, [$itemId], $baseUrl);
    }

    /** @return array<int,array<string,mixed>> */
    public function reorderItems(AccountProfile $profile, string $groupId, array $ids): array
    {
        return $this->change($profile, function (array &$groups) use ($groupId, $ids): void {
            $group = $this->groupIndex($groups, $groupId);
            $groups[$group]['items'] = $this->reorder($this->array($groups[$group]['items'] ?? []), $ids, 'item_id');
        });
    }

    /**
     * @param  array<int, string>|callable(array<int, array<string, mixed>>): array<int, string>  $affectedMediaItemIds
     * @return array<int,array<string,mixed>>
     */
    private function change(
        AccountProfile $profile,
        callable $mutation,
        array|callable $affectedMediaItemIds = [],
        ?string $baseUrl = null,
    ): array {
        if (! $this->isAllowed($profile)) {
            $this->fail('gallery_groups', 'Gallery is not enabled for this profile type.');
        }
        $resolveAffectedItemsWithinTransaction = is_callable($affectedMediaItemIds);
        $backup = $baseUrl === null || $resolveAffectedItemsWithinTransaction || $affectedMediaItemIds === []
            ? null
            : $this->media->captureGalleryItemMutationBackup($profile, $affectedMediaItemIds, $baseUrl);
        $updated = $this->profiles->update($profile, [], mutateWithinTransaction: function (AccountProfile $stored) use ($mutation, $affectedMediaItemIds, $baseUrl, $resolveAffectedItemsWithinTransaction, &$backup): void {
            $groups = $this->array($stored->gallery_groups ?? []);
            if ($baseUrl !== null && $resolveAffectedItemsWithinTransaction && $backup === null) {
                $resolvedItemIds = $affectedMediaItemIds($groups);
                $backup = $resolvedItemIds === []
                    ? null
                    : $this->media->captureGalleryItemMutationBackup($stored, $resolvedItemIds, $baseUrl);
            }
            $mutation($groups);
            foreach ($groups as $groupOrder => &$group) {
                $group['order'] = $groupOrder;
                $group['items'] = $this->array($group['items'] ?? []);
                foreach ($group['items'] as $itemOrder => &$item) {
                    $item['order'] = $itemOrder;
                    $item['type'] = $this->type($item['type'] ?? 'photo');
                } unset($item);
            } unset($group);
            $stored->gallery_groups = $groups;
        }, compensateKnownRollback: $baseUrl === null || (! $resolveAffectedItemsWithinTransaction && $backup === null)
            ? null
            : static function () use (&$backup): void {
                $backup?->restore();
            }, useAggregateRevisionCas: false);

        return $this->gallery->formatForRead($updated, request()->getSchemeAndHttpHost());
    }

    private function groupIndex(array $groups, string $id): int
    {
        foreach ($groups as $i => $group) {
            if ((string) ($group['group_id'] ?? '') === $id) {
                return $i;
            }
        } abort(404);
    }

    private function itemIndex(array $items, string $id): int
    {
        foreach ($items as $i => $item) {
            if ((string) ($item['item_id'] ?? '') === $id) {
                return $i;
            }
        } abort(404);
    }

    private function type(mixed $type): string
    {
        $value = trim((string) $type);
        if (! in_array($value, ['photo', 'youtube'], true)) {
            $this->fail('type', 'Gallery item type must be photo or youtube.');
        }

        return $value;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function youtube(mixed $url): string
    {
        $parts = parse_url(trim((string) $url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port']) || ! in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'], true)) {
            $this->fail('youtube_url', 'A valid YouTube HTTPS URL is required.');
        } $path = trim((string) ($parts['path'] ?? ''), '/');
        parse_str((string) ($parts['query'] ?? ''), $query);
        $id = $host === 'youtu.be' ? $path : ($path === 'watch' ? ($query['v'] ?? '') : (preg_match('#^(shorts|embed)/([^/]+)$#', $path, $m) ? $m[2] : ''));
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            $this->fail('youtube_url', 'A valid YouTube video URL is required.');
        }

        return $id;
    }

    private function reorder(array $records, array $ids, string $key): array
    {
        $actual = array_map(fn ($record) => (string) ($record[$key] ?? ''), $records);
        if (count($ids) !== count($actual) || count(array_unique($ids)) !== count($ids) || array_diff($actual, $ids) !== [] || array_diff($ids, $actual) !== []) {
            $this->fail($key.'s', 'The order must contain every current id exactly once.');
        } $map = [];
        foreach ($records as $record) {
            $map[(string) $record[$key]] = $record;
        }

        return array_map(fn ($id) => $map[$id], $ids);
    }

    /** @return array<int,mixed> */
    private function array(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        } if ($value instanceof BSONArray || $value instanceof BSONDocument) {
            return $value->getArrayCopy();
        } if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return [];
    }
}
