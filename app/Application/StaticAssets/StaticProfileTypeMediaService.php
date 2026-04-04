<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Models\Tenants\StaticProfileType;
use Belluga\Media\Application\ModelMediaService;
use Belluga\Media\Support\MediaModelDefinition;
use Illuminate\Http\Request;

class StaticProfileTypeMediaService
{
    private const LEGACY_PUBLIC_PATH_PREFIX = '/static-profile-types';

    private const CANONICAL_PUBLIC_PATH_PREFIX = '/api/v1/media/static-profile-types';

    public function __construct(
        private readonly ModelMediaService $modelMediaService,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function applyUploads(Request $request, StaticProfileType $type): array
    {
        return $this->modelMediaService->applyUploads($request, $type, $this->definition());
    }

    public function resolveMediaPath(StaticProfileType $type, string $kind): ?string
    {
        return $this->modelMediaService->resolveMediaPath($type, $kind, $this->definition());
    }

    public function resolveMediaPathForBaseUrl(
        StaticProfileType $type,
        string $kind,
        ?string $baseUrl,
    ): ?string {
        return $this->modelMediaService->resolveMediaPathForBaseUrl(
            $type,
            $kind,
            $this->definition(),
            $baseUrl,
        );
    }

    public function buildPublicUrl(
        string $baseUrl,
        StaticProfileType $type,
        string $kind,
        string|int|null $version = null,
    ): string {
        return $this->modelMediaService->buildPublicUrl(
            $baseUrl,
            $type,
            $kind,
            $this->definition(),
            $version,
        );
    }

    public function normalizePublicUrl(
        string $baseUrl,
        StaticProfileType $type,
        string $kind,
        ?string $rawUrl,
    ): ?string {
        return $this->modelMediaService->normalizePublicUrl(
            $baseUrl,
            $type,
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
            storageDirectory: 'static_profile_types',
            slots: ['type_asset'],
        );
    }
}
