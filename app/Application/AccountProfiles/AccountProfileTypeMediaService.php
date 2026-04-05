<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\TenantProfileType;
use Belluga\Media\Application\ModelMediaService;
use Belluga\Media\Support\MediaModelDefinition;
use Illuminate\Http\Request;

class AccountProfileTypeMediaService
{
    private const LEGACY_PUBLIC_PATH_PREFIX = '/account-profile-types';

    private const CANONICAL_PUBLIC_PATH_PREFIX = '/api/v1/media/account-profile-types';

    public function __construct(
        private readonly ModelMediaService $modelMediaService,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function applyUploads(Request $request, TenantProfileType $type): array
    {
        return $this->modelMediaService->applyUploads($request, $type, $this->definition());
    }

    public function resolveMediaPath(TenantProfileType $type, string $kind): ?string
    {
        return $this->modelMediaService->resolveMediaPath($type, $kind, $this->definition());
    }

    public function resolveMediaPathForBaseUrl(
        TenantProfileType $type,
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
        TenantProfileType $type,
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
        TenantProfileType $type,
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
            storageDirectory: 'account_profile_types',
            slots: ['type_asset'],
        );
    }
}
