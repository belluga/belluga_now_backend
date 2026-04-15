<?php

declare(strict_types=1);

namespace App\Application\Branding;

use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Belluga\Media\Application\ModelMediaService;
use Belluga\Media\Support\MediaModelDefinition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class BrandingPublicWebMediaService
{
    private const KIND = 'default_image';

    private const LEGACY_FILE_BASENAME = 'default-image';

    private const LEGACY_PUBLIC_PATH_PREFIX = '/branding-public-web';

    private const CANONICAL_PUBLIC_PATH_PREFIX = '/api/v1/media/branding-public-web';

    public function __construct(
        private readonly ModelMediaService $modelMediaService,
    ) {}

    public function storeDefaultImage(
        string $baseUrl,
        Tenant|Landlord $brandable,
        UploadedFile $file,
    ): string {
        return $this->modelMediaService->storeUpload(
            baseUrl: $this->storageBaseUrl($brandable, $baseUrl),
            model: $brandable,
            kind: self::KIND,
            file: $file,
            definition: $this->definition(),
        );
    }

    public function buildPublicUrl(
        string $baseUrl,
        Tenant|Landlord $brandable,
        string|int|null $version = null,
    ): string {
        return $this->modelMediaService->buildPublicUrl(
            $baseUrl,
            $brandable,
            self::KIND,
            $this->definition(),
            $version,
        );
    }

    public function normalizePublicUrl(
        string $baseUrl,
        Tenant|Landlord $brandable,
        ?string $rawUrl,
    ): ?string {
        $normalized = $this->modelMediaService->normalizePublicUrl(
            $baseUrl,
            $brandable,
            self::KIND,
            $this->definition(),
            $rawUrl,
        );

        if ($normalized === null) {
            return null;
        }

        $legacyNormalized = $this->normalizeLegacyStorageUrl($baseUrl, $brandable, $normalized);

        return $legacyNormalized;
    }

    public function resolveMediaPathForBaseUrl(
        Tenant|Landlord $brandable,
        ?string $baseUrl,
    ): ?string {
        $path = $this->modelMediaService->resolveMediaPathForBaseUrl(
            $brandable,
            self::KIND,
            $this->definition(),
            $this->storageBaseUrl($brandable, $baseUrl),
        );

        if ($path !== null) {
            return $path;
        }

        return $this->resolveLegacyStoragePath($brandable);
    }

    /**
     * @return array{width:string,height:string,type:string}|array{}
     */
    public function resolveImagePropertiesForBaseUrl(
        Tenant|Landlord $brandable,
        ?string $baseUrl,
    ): array {
        $path = $this->resolveMediaPathForBaseUrl($brandable, $baseUrl);
        if ($path === null) {
            return [];
        }

        $absolutePath = Storage::disk('public')->path($path);
        if (! is_file($absolutePath)) {
            return [];
        }

        $imageSize = @getimagesize($absolutePath);
        if (! is_array($imageSize)) {
            return [];
        }

        $mimeType = $imageSize['mime'] ?? null;
        if (! is_string($mimeType) || trim($mimeType) === '') {
            $mimeType = isset($imageSize[2]) && is_int($imageSize[2])
                ? image_type_to_mime_type($imageSize[2])
                : '';
        }

        return [
            'width' => (string) ($imageSize[0] ?? ''),
            'height' => (string) ($imageSize[1] ?? ''),
            'type' => trim((string) $mimeType),
        ];
    }

    private function normalizeLegacyStorageUrl(
        string $baseUrl,
        Tenant|Landlord $brandable,
        string $rawUrl,
    ): string {
        $path = parse_url($rawUrl, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            return $rawUrl;
        }

        if (! $this->matchesLegacyStoragePath($brandable, $path)) {
            return $rawUrl;
        }

        $version = $this->extractVersionFromUri($rawUrl);

        return $this->buildPublicUrl($baseUrl, $brandable, $version);
    }

    private function matchesLegacyStoragePath(Tenant|Landlord $brandable, string $path): bool
    {
        foreach ($this->allowedExtensions() as $extension) {
            $expected = "/storage/{$this->legacyStoragePath($brandable, $extension)}";
            if ($path === $expected) {
                return true;
            }
        }

        return false;
    }

    private function resolveLegacyStoragePath(Tenant|Landlord $brandable): ?string
    {
        foreach ($this->allowedExtensions() as $extension) {
            $path = $this->legacyStoragePath($brandable, $extension);
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function legacyStoragePath(Tenant|Landlord $brandable, string $extension): string
    {
        return sprintf(
            'tenants/%s/public-web/%s.%s',
            $this->legacyScope($brandable),
            self::LEGACY_FILE_BASENAME,
            $extension,
        );
    }

    private function legacyScope(Tenant|Landlord $brandable): string
    {
        if ($brandable instanceof Landlord) {
            return 'landlord';
        }

        $slug = trim((string) $brandable->slug);

        return $slug !== '' ? $slug : 'tenant';
    }

    private function storageBaseUrl(Tenant|Landlord $brandable, ?string $baseUrl): ?string
    {
        if ($brandable instanceof Landlord) {
            return null;
        }

        return $baseUrl;
    }

    /**
     * @return array<int, string>
     */
    private function allowedExtensions(): array
    {
        return ['jpg', 'jpeg', 'png', 'webp'];
    }

    private function definition(): MediaModelDefinition
    {
        return new MediaModelDefinition(
            legacyPublicPathPrefix: self::LEGACY_PUBLIC_PATH_PREFIX,
            canonicalPublicPathPrefix: self::CANONICAL_PUBLIC_PATH_PREFIX,
            storageDirectory: 'branding_public_web',
            slots: [self::KIND],
        );
    }

    private function extractVersionFromUri(string $value): ?string
    {
        $query = parse_url($value, PHP_URL_QUERY);
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
}
