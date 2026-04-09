<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Models\Tenants\EventType;
use Belluga\Media\Application\ModelMediaService;
use Belluga\Media\Support\MediaModelDefinition;
use Illuminate\Http\Request;

class EventTypeMediaService
{
    private const LEGACY_PUBLIC_PATH_PREFIX = '/event-types';

    private const CANONICAL_PUBLIC_PATH_PREFIX = '/api/v1/media/event-types';

    public function __construct(
        private readonly ModelMediaService $modelMediaService,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function applyUploads(Request $request, EventType $type): array
    {
        return $this->modelMediaService->applyUploads($request, $type, $this->definition());
    }

    public function resolveMediaPathForBaseUrl(
        EventType $type,
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

    public function normalizePublicUrl(
        string $baseUrl,
        EventType $type,
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
            storageDirectory: 'event_types',
            slots: ['type_asset'],
        );
    }
}
