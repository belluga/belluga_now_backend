<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use Belluga\Media\Application\ModelMediaService;
use Belluga\Media\Support\MediaModelDefinition;
use Illuminate\Http\Request;

class AccountProfileMediaService
{
    private const LEGACY_PUBLIC_PATH_PREFIX = '/account-profiles';

    private const CANONICAL_PUBLIC_PATH_PREFIX = '/api/v1/media/account-profiles';

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

    private function definition(): MediaModelDefinition
    {
        return new MediaModelDefinition(
            legacyPublicPathPrefix: self::LEGACY_PUBLIC_PATH_PREFIX,
            canonicalPublicPathPrefix: self::CANONICAL_PUBLIC_PATH_PREFIX,
            storageDirectory: 'account_profiles',
            slots: ['avatar', 'cover'],
        );
    }
}
