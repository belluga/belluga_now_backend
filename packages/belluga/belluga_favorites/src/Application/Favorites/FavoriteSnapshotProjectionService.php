<?php

declare(strict_types=1);

namespace Belluga\Favorites\Application\Favorites;

use Belluga\Favorites\Contracts\FavoriteSnapshotBuilderContract;
use Belluga\Favorites\Contracts\FavoritesRegistryContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;

class FavoriteSnapshotProjectionService
{
    public function __construct(
        private readonly FavoritesRegistryContract $registry,
        private readonly Container $container,
    ) {}

    public function rebuild(string $registryKey, string $targetId): void
    {
        $definition = $this->registry->find($registryKey);
        if (! $definition) {
            return;
        }

        $builder = $this->container->make($definition->snapshotBuilderClass);
        if (! $builder instanceof FavoriteSnapshotBuilderContract) {
            throw new \RuntimeException(sprintf(
                'Favorites snapshot builder [%s] must implement [%s].',
                $definition->snapshotBuilderClass,
                FavoriteSnapshotBuilderContract::class
            ));
        }

        $selector = [
            'registry_key' => $definition->registryKey,
            'target_type' => $definition->targetType,
            'target_id' => $targetId,
        ];

        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection($definition->resolvedSnapshotCollection());

        $payload = $builder->build($targetId, $definition);
        if ($payload === null) {
            $collection->deleteOne($selector);

            return;
        }

        $normalizedPayload = $this->normalizeMongoValue($payload);
        $set = array_merge($selector, $normalizedPayload, [
            'updated_at' => $this->normalizeMongoValue(Carbon::now()),
        ]);

        $collection->updateOne(
            $selector,
            ['$set' => $set],
            ['upsert' => true]
        );
    }

    private function normalizeMongoValue(mixed $value): mixed
    {
        if ($value instanceof UTCDateTime) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return new UTCDateTime($value);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeMongoValue($item);
            }

            return $normalized;
        }

        return $value;
    }
}
