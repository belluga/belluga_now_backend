<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

class AccountProfileTypeCapabilityRepairer
{
    public function __construct(
        private readonly AccountProfileTypeCapabilityCatalog $catalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function repairableFieldFilter(string $key): array
    {
        $validKey = $this->catalogKey($key);
        $fieldPath = "capabilities.{$validKey}";

        return [
            '$or' => [
                [$fieldPath => ['$exists' => false]],
                [
                    '$expr' => [
                        '$ne' => [
                            ['$type' => '$'.$fieldPath],
                            'bool',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function defaultsForType(string $type): array
    {
        return $this->catalog->completeForPersistence($type);
    }

    private function catalogKey(string $key): string
    {
        foreach ($this->catalog->definitions() as $definition) {
            if ($definition['key'] === $key) {
                return $key;
            }
        }

        throw new \InvalidArgumentException("Unknown capability key [{$key}]");
    }
}
