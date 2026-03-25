<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Models\Tenants\StaticAsset;
use Belluga\Media\Application\ModelMediaService;
use Belluga\Media\Support\MediaModelDefinition;
use Illuminate\Http\Request;

class StaticAssetMediaService
{
    private const LEGACY_PUBLIC_PATH_PREFIX = '/static-assets';

    private const CANONICAL_PUBLIC_PATH_PREFIX = '/api/v1/media/static-assets';

    public function __construct(
        private readonly ModelMediaService $modelMediaService,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function applyUploads(Request $request, StaticAsset $asset): array
    {
        return $this->modelMediaService->applyUploads($request, $asset, $this->definition());
    }

    public function resolveMediaPath(StaticAsset $asset, string $kind): ?string
    {
        return $this->modelMediaService->resolveMediaPath($asset, $kind, $this->definition());
    }

    public function resolveMediaPathForBaseUrl(
        StaticAsset $asset,
        string $kind,
        ?string $baseUrl,
    ): ?string {
        return $this->modelMediaService->resolveMediaPathForBaseUrl(
            $asset,
            $kind,
            $this->definition(),
            $baseUrl,
        );
    }

    public function buildPublicUrl(
        string $baseUrl,
        StaticAsset $asset,
        string $kind,
        string|int|null $version = null,
    ): string {
        return $this->modelMediaService->buildPublicUrl(
            $baseUrl,
            $asset,
            $kind,
            $this->definition(),
            $version,
        );
    }

    public function normalizePublicUrl(
        string $baseUrl,
        StaticAsset $asset,
        string $kind,
        ?string $rawUrl,
    ): ?string {
        return $this->modelMediaService->normalizePublicUrl(
            $baseUrl,
            $asset,
            $kind,
            $this->definition(),
            $rawUrl,
        );
    }

    private function definition(): MediaModelDefinition
    {
        return new MediaModelDefinition(
            legacyPublicPathPrefix: self::LEGACY_PUBLIC_PATH_PREFIX,
            canonicalPublicPathPrefix: self::CANONICAL_PUBLIC_PATH_PREFIX,
            storageDirectory: 'static_assets',
            slots: ['avatar', 'cover'],
        );
    }
}

