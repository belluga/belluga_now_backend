<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use Belluga\Media\Application\CanonicalImageProcessor;
use Belluga\Media\Application\ModelMediaService;
use Belluga\Media\Support\MediaModelDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class AccountProfileMediaService
{
    private const LEGACY_PUBLIC_PATH_PREFIX = '/account-profiles';

    private const CANONICAL_PUBLIC_PATH_PREFIX = '/api/v1/media/account-profiles';

    private const GALLERY_KIND_PREFIX = 'gallery-item-';

    private const GALLERY_PUBLIC_PATH_SEGMENT = 'gallery';

    public function __construct(
        private readonly ModelMediaService $modelMediaService,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function applyUploads(Request $request, AccountProfile $profile): array
    {
        return $this->modelMediaService->applyUploads($request, $profile, $this->definition());
    }

    public function resolveMediaPath(AccountProfile $profile, string $kind): ?string
    {
        return $this->modelMediaService->resolveMediaPath($profile, $kind, $this->definition());
    }

    public function resolveMediaPathForBaseUrl(
        AccountProfile $profile,
        string $kind,
        ?string $baseUrl,
    ): ?string {
        return $this->modelMediaService->resolveMediaPathForBaseUrl(
            $profile,
            $kind,
            $this->definition(),
            $baseUrl,
        );
    }

    public function buildPublicUrl(
        string $baseUrl,
        AccountProfile $profile,
        string $kind,
        string|int|null $version = null,
    ): string {
        return $this->modelMediaService->buildPublicUrl(
            $baseUrl,
            $profile,
            $kind,
            $this->definition(),
            $version,
        );
    }

    public function normalizePublicUrl(
        string $baseUrl,
        AccountProfile $profile,
        string $kind,
        ?string $rawUrl,
    ): ?string {
        return $this->modelMediaService->normalizePublicUrl(
            $baseUrl,
            $profile,
            $kind,
            $this->definition(),
            $rawUrl,
        );
    }

    /**
     * @return array{media_path:string, version:string}
     */
    public function storeGalleryUpload(
        string $baseUrl,
        AccountProfile $profile,
        string $itemId,
        UploadedFile $file,
    ): array {
        $kind = $this->galleryKind($itemId);
        $this->modelMediaService->storeUpload(
            baseUrl: $baseUrl,
            model: $profile,
            kind: $kind,
            file: $file,
            definition: $this->definition(),
        );

        $version = $this->modelMediaService->resolveCurrentMediaVersion(
            $profile,
            $kind,
            $this->definition(),
            $baseUrl,
        );

        return [
            'media_path' => $this->buildGalleryPublicPath($profile, $itemId),
            'version' => $version ?? (string) time(),
        ];
    }

    public function removeGalleryUpload(
        AccountProfile $profile,
        string $itemId,
        ?string $baseUrl = null,
    ): void {
        $this->modelMediaService->removeUpload(
            model: $profile,
            kind: $this->galleryKind($itemId),
            definition: $this->definition(),
            baseUrl: $baseUrl,
        );
    }

    /**
     * Erases all profile-owned media before an irreversible account deletion.
     */
    public function removeAllUploads(AccountProfile $profile, ?string $baseUrl = null): void
    {
        foreach (['avatar', 'cover'] as $kind) {
            $this->modelMediaService->removeUpload(
                model: $profile,
                kind: $kind,
                definition: $this->definition(),
                baseUrl: $baseUrl,
            );
        }

        foreach ($profile->gallery_groups ?? [] as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group['items'] ?? [] as $item) {
                $itemId = trim((string) (is_array($item) ? ($item['item_id'] ?? '') : ''));
                if ($itemId !== '') {
                    $this->removeGalleryUpload($profile, $itemId, $baseUrl);
                }
            }
        }
    }

    public function galleryItemExists(AccountProfile $profile, string $itemId): bool
    {
        foreach ($profile->gallery_groups ?? [] as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group['items'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (trim((string) ($item['item_id'] ?? '')) === $itemId) {
                    return true;
                }
            }
        }

        return false;
    }

    public function resolveGalleryMediaPathForBaseUrl(
        AccountProfile $profile,
        string $itemId,
        ?string $variant,
        ?string $baseUrl,
    ): ?string {
        return $this->modelMediaService->resolveVariantMediaPathForBaseUrl(
            $profile,
            $this->galleryKind($itemId),
            $this->normalizeGalleryVariant($variant),
            $this->definition(),
            $baseUrl,
        );
    }

    public function buildGalleryPublicPath(AccountProfile $profile, string $itemId): string
    {
        return sprintf(
            '%s/%s/%s/%s',
            self::CANONICAL_PUBLIC_PATH_PREFIX,
            (string) $profile->getKey(),
            self::GALLERY_PUBLIC_PATH_SEGMENT,
            $itemId,
        );
    }

    public function buildGalleryPublicUrl(
        string $baseUrl,
        AccountProfile $profile,
        string $itemId,
        string $version,
        ?string $variant = null,
    ): string {
        $query = http_build_query(array_filter([
            'v' => trim($version),
            'variant' => $this->normalizeGalleryVariant($variant),
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));

        return rtrim($baseUrl, '/').$this->buildGalleryPublicPath($profile, $itemId).($query !== '' ? '?'.$query : '');
    }

    /**
     * @return array<int, string>
     */
    public function galleryVariants(): array
    {
        return array_keys(CanonicalImageProcessor::DEFAULT_PUBLIC_VARIANTS);
    }

    public function defaultGalleryVariant(): string
    {
        return 'modal';
    }

    public function isGalleryVariant(?string $variant): bool
    {
        return $this->normalizeGalleryVariant($variant) !== null;
    }

    private function definition(): MediaModelDefinition
    {
        return new MediaModelDefinition(
            legacyPublicPathPrefix: self::LEGACY_PUBLIC_PATH_PREFIX,
            canonicalPublicPathPrefix: self::CANONICAL_PUBLIC_PATH_PREFIX,
            storageDirectory: 'account_profiles',
            slots: ['avatar', 'cover'],
        );
    }

    private function galleryKind(string $itemId): string
    {
        return self::GALLERY_KIND_PREFIX.$itemId;
    }

    private function normalizeGalleryVariant(?string $variant): ?string
    {
        $normalized = strtolower(trim((string) $variant));
        if ($normalized === '') {
            return null;
        }

        return in_array($normalized, $this->galleryVariants(), true) ? $normalized : null;
    }
}
