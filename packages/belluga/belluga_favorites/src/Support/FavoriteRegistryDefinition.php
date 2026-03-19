<?php

declare(strict_types=1);

namespace Belluga\Favorites\Support;

final class FavoriteRegistryDefinition
{
    /**
     * @param  array<int, string>  $sharedEnvelopeFields
     */
    public function __construct(
        public readonly string $registryKey,
        public readonly string $targetType,
        public readonly string $snapshotBuilderClass,
        public readonly ?string $snapshotCollection = null,
        public readonly bool $requiresSpecificIndexes = false,
        public readonly array $sharedEnvelopeFields = ['registry_key', 'target_type', 'target_id', 'updated_at'],
    ) {}

    public function resolvedSnapshotCollection(): string
    {
        return $this->snapshotCollection ?? 'favoritable_snapshots';
    }
}
