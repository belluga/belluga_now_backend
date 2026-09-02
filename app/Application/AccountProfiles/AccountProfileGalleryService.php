<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

final class AccountProfileGalleryService
{
    public function __construct(
        private readonly AccountProfileMediaService $mediaService,
        private readonly AccountProfileTypeSetProvider $typeSetProvider,
    ) {}

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

                $type = trim((string) ($rawItem['type'] ?? 'photo'));
                if (! in_array($type, ['photo', 'youtube'], true)) {
                    continue;
                }
                $item = [
                    '_source_index' => $itemIndex,
                    'item_id' => $itemId,
                    'type' => $type,
                    'title' => $this->normalizeNullableString($rawItem['title'] ?? null),
                    'description' => $this->normalizeNullableString($rawItem['description'] ?? null),
                    'order' => $this->normalizeOrder($rawItem['order'] ?? null, $itemIndex),
                ];
                if ($type === 'youtube') {
                    $videoId = trim((string) ($rawItem['youtube_video_id'] ?? ''));
                    if (! preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
                        continue;
                    }
                    $item['youtube_video_id'] = $videoId;
                    $item['player_aspect_ratio'] = $this->normalizePlayerAspectRatio($rawItem['player_aspect_ratio'] ?? null);
                } else {
                    $version = $this->normalizeStoredVersion($rawItem['version'] ?? null);
                    $item += ['image_url' => $this->mediaService->buildGalleryPublicUrl(
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
                        )];
                }
                $items[] = $item;
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
                    static fn (array $item, int $order): array => $item['type'] === 'youtube'
                        ? ['item_id' => $item['item_id'], 'type' => 'youtube', 'title' => $item['title'], 'description' => $item['description'], 'order' => $order, 'youtube_video_id' => $item['youtube_video_id'], 'player_aspect_ratio' => $item['player_aspect_ratio']]
                        : ['item_id' => $item['item_id'], 'type' => 'photo', 'title' => $item['title'], 'description' => $item['description'], 'order' => $order, 'image_url' => $item['image_url'], 'thumb_url' => $item['thumb_url'], 'card_url' => $item['card_url'], 'modal_url' => $item['modal_url']],
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

    private function normalizePlayerAspectRatio(mixed $value): float
    {
        if (! is_numeric($value)) {
            return YoutubeVideoMetadataResolver::DEFAULT_ASPECT_RATIO;
        }

        $ratio = (float) $value;

        return $ratio > 0 ? $ratio : YoutubeVideoMetadataResolver::DEFAULT_ASPECT_RATIO;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formatForPublicDetail(AccountProfile $profile, string $baseUrl): array
    {
        if (! $this->isExposedForProfile($profile)) {
            return [];
        }

        return array_values(array_filter(
            $this->formatForRead($profile, $baseUrl),
            static fn (array $group): bool => $group['items'] !== [],
        ));
    }

    public function isExposedForProfile(AccountProfile $profile): bool
    {
        $profileType = trim((string) ($profile->profile_type ?? ''));

        return $this->profileTypeAllowsGallery($profileType);
    }

    private function profileTypeAllowsGallery(string $profileType): bool
    {
        return $this->typeSetProvider->hasGalleryEnabled($profileType);
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
}
