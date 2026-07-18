<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use InvalidArgumentException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

final class AccountProfileTypeCapabilityRepairer
{
    public function __construct(
        private readonly AccountProfileTypeCapabilityCatalog $catalog,
    ) {}

    public function repairCollection(Collection $collection, UTCDateTime $now): int
    {
        $modifiedCount = 0;
        $typeSpecificDefaults = $this->catalog->typeSpecificPersistenceDefaults();

        foreach ($typeSpecificDefaults as $type => $capabilities) {
            $modifiedCount += $this->repairFields(
                $collection,
                ['type' => $type],
                $capabilities,
                $now,
            );
        }

        return $modifiedCount + $this->repairFields(
            $collection,
            ['type' => ['$nin' => array_keys($typeSpecificDefaults)]],
            $this->catalog->completeForPersistence(''),
            $now,
        );
    }

    public function repairDocument(
        Collection $collection,
        string $type,
        UTCDateTime $now,
    ): int {
        return $this->repairFields(
            $collection,
            ['type' => trim($type)],
            $this->catalog->completeForPersistence($type),
            $now,
        );
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    public function repairableFieldFilter(string $capability): array
    {
        if (! $this->isKnownCapability($capability)) {
            throw new InvalidArgumentException("Unknown Account Profile Type capability [{$capability}].");
        }

        return ["capabilities.{$capability}" => ['$not' => ['$type' => 'bool']]];
    }

    /**
     * @param  array<string, mixed>  $baseFilter
     * @param  array<string, bool>  $capabilities
     */
    private function repairFields(
        Collection $collection,
        array $baseFilter,
        array $capabilities,
        UTCDateTime $now,
    ): int {
        $modifiedCount = 0;

        foreach ($capabilities as $capability => $default) {
            $result = $collection->updateMany(
                array_merge($baseFilter, $this->repairableFieldFilter($capability)),
                [
                    '$set' => [
                        "capabilities.{$capability}" => $default,
                        'updated_at' => $now,
                    ],
                ],
            );
            $modifiedCount += $result->getModifiedCount();
        }

        return $modifiedCount;
    }

    private function isKnownCapability(string $capability): bool
    {
        foreach ($this->catalog->definitions() as $definition) {
            if ($definition['key'] === $capability) {
                return true;
            }
        }

        return false;
    }
}
