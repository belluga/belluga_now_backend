<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityResolverContract;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class AccountProfileRegistrySeeder
{
    public function __construct(
        private readonly ?AccountProfileRegistryDefaultUpserter $defaultUpserter = null,
        private readonly ?AccountProfileCapabilityResolverContract $capabilityResolver = null,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function defaults(): array
    {
        $resolver = $this->capabilityResolver ?? app(AccountProfileCapabilityResolverContract::class);

        return [
            [
                'type' => 'personal',
                'label' => 'Personal',
                'allowed_taxonomies' => [],
                'poi_visual' => null,
                'capability_revision' => 0,
                'host_admission_fence_revision' => 0,
                'capabilities' => $resolver->materializeConfigurationForCreation($this->configuration([
                    'is_queryable' => false,
                    'is_publicly_navigable' => false,
                    'is_favoritable' => true,
                    'is_inviteable' => true,
                    'is_publicly_discoverable' => false,
                    'location_policy' => 'disabled',
                    'has_gallery' => false,
                ])),
            ],
            [
                'type' => 'artist',
                'label' => 'Artist',
                'allowed_taxonomies' => [],
                'poi_visual' => null,
                'capability_revision' => 0,
                'host_admission_fence_revision' => 0,
                'capabilities' => $resolver->materializeConfigurationForCreation($this->configuration([
                    'is_queryable' => true,
                    'is_publicly_navigable' => true,
                    'is_favoritable' => true,
                    'is_inviteable' => false,
                    'is_publicly_discoverable' => true,
                    'location_policy' => 'disabled',
                    'has_gallery' => true,
                ])),
            ],
            [
                'type' => 'venue',
                'label' => 'Venue',
                'allowed_taxonomies' => [],
                'poi_visual' => [
                    'mode' => 'icon',
                    'icon' => 'place',
                    'color' => '#E53935',
                ],
                'capability_revision' => 0,
                'host_admission_fence_revision' => 0,
                'capabilities' => $resolver->materializeConfigurationForCreation($this->configuration([
                    'is_queryable' => true,
                    'is_publicly_navigable' => true,
                    'is_favoritable' => true,
                    'is_inviteable' => false,
                    'is_publicly_discoverable' => true,
                    'location_policy' => 'required',
                    'is_map_poi_enabled' => true,
                    'is_physical_host_enabled' => true,
                    'has_gallery' => true,
                ])),
            ],
        ];
    }

    public function ensureDefaults(): void
    {
        $this->synchronizeCapabilityDefinitions();
        $this->ensureDefaultTypes(['personal', 'artist', 'venue'], repairExisting: true);
    }

    public function ensurePersonalDefault(): void
    {
        $this->ensureDefaultTypes(['personal'], repairExisting: false);
    }

    /**
     * @param  array<int, string>  $types
     */
    private function ensureDefaultTypes(array $types, bool $repairExisting): void
    {
        $resolver = $this->capabilityResolver ?? app(AccountProfileCapabilityResolverContract::class);
        $upserter = $this->defaultUpserter ?? new AccountProfileRegistryDefaultUpserter;
        $now = new UTCDateTime((int) (microtime(true) * 1000));
        $requestedTypes = array_values(array_filter(array_map(
            static fn (mixed $type): string => trim((string) $type),
            $types
        )));

        foreach ($this->defaults() as $entry) {
            $type = trim((string) ($entry['type'] ?? ''));
            if ($type === '' || ! in_array($type, $requestedTypes, true)) {
                continue;
            }

            $existing = TenantProfileType::query()
                ->where('type', $type)
                ->first();

            if (! $existing instanceof TenantProfileType) {
                $entry['capabilities'] = $resolver->configurationForPersistence(
                    $this->arrayFrom($entry['capabilities'] ?? []),
                );
                $upserter->ensureDefault($entry, $now);

                continue;
            }

            if ($repairExisting) {
                $this->repairDefaultCapabilities($existing, $entry);
            }
        }
    }

    private function synchronizeCapabilityDefinitions(): void
    {
        $resolver = $this->capabilityResolver ?? app(AccountProfileCapabilityResolverContract::class);
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_capability_definitions');
        $definitions = array_values($resolver->definitions());

        foreach ($definitions as $definition) {
            $collection->replaceOne(
                ['key' => $definition['key']],
                $resolver->definitionForPersistence($definition),
                ['upsert' => true],
            );
        }
        $collection->deleteMany([
            'key' => ['$nin' => array_column($definitions, 'key')],
        ]);
        $collection->createIndex(
            ['key' => 1],
            [
                'name' => 'uq_account_profile_capability_definitions_key_v1',
                'unique' => true,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function repairDefaultCapabilities(
        TenantProfileType $type,
        array $entry,
    ): void {
        $current = $this->arrayFrom($type->capabilities ?? []);
        $defaults = $this->arrayFrom($entry['capabilities'] ?? []);
        $resolver = $this->capabilityResolver ?? app(AccountProfileCapabilityResolverContract::class);
        $next = $resolver->repairConfiguration($current, $defaults);

        if ($next === $current) {
            return;
        }

        $expectedRevision = max(0, (int) ($type->capability_revision ?? 0));
        $filter = ['type' => (string) $type->type];
        $filter += $expectedRevision === 0
            ? ['$or' => [
                ['capability_revision' => 0],
                ['capability_revision' => ['$exists' => false]],
            ]]
            : ['capability_revision' => $expectedRevision];
        $result = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types')
            ->updateOne($filter, [
                '$set' => [
                    'capabilities' => $resolver->configurationForPersistence($next),
                    'updated_at' => new UTCDateTime((int) (microtime(true) * 1000)),
                ],
                '$inc' => [
                    'capability_revision' => 1,
                    'host_admission_fence_revision' => 1,
                ],
            ]);

        if ($result->getMatchedCount() !== 1) {
            throw new ConcurrencyConflictException(
                "Account Profile Type [{$type->type}] changed during registry repair."
            );
        }

        AccountProfileTypeSetProvider::bumpRevision();
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayFrom(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, bool|string>  $values
     * @return array<string, array{value:bool|string,parameters:array<string,int>}>
     */
    private function configuration(array $values): array
    {
        $configuration = [];
        foreach ($values as $key => $value) {
            $configuration[$key] = ['value' => $value, 'parameters' => []];
        }

        return $configuration;
    }
}
