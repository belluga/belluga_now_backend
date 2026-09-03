<?php

declare(strict_types=1);

use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;
use App\Application\AccountProfiles\AccountProfileTypeCapabilityRepairer;
use App\Application\AccountProfiles\AccountProfileTypeIndexManifest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

return new class extends Migration
{
    public function up(): void
    {
        /** @var Collection<array<string, mixed>> $collection */
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');

        $repairer = new AccountProfileTypeCapabilityRepairer(new AccountProfileTypeCapabilityCatalog);
        $collection->updateMany(
            $repairer->repairableFieldFilter(AccountProfileTypeCapabilityCatalog::HAS_EXTERNAL_LINKS),
            ['$set' => [
                'capabilities.'.AccountProfileTypeCapabilityCatalog::HAS_EXTERNAL_LINKS => false,
                'updated_at' => new UTCDateTime((int) now()->getTimestampMs()),
            ]],
        );

        $this->ensureIndex($collection, $this->externalLinksIndexDefinition());
    }

    public function down(): void {}

    /**
     * @return array{name:string, keys:array<string, int>, collation:array{locale:string}}
     */
    private function externalLinksIndexDefinition(): array
    {
        foreach ((new AccountProfileTypeIndexManifest)->definitions() as $definition) {
            if ($definition['id'] === 'C-17') {
                return $definition;
            }
        }

        throw new RuntimeException('Missing Account Profile Type external-links index manifest row C-17.');
    }

    /**
     * @param  Collection<array<string, mixed>>  $collection
     * @param  array{name:string, keys:array<string, int>, collation:array{locale:string}}  $definition
     */
    private function ensureIndex(Collection $collection, array $definition): void
    {
        foreach ($collection->listIndexes() as $index) {
            $name = (string) $index->getName();
            $keys = $this->arrayFrom($index->getKey());

            if ($name === $definition['name'] && $keys === $definition['keys']) {
                return;
            }

            if ($name === $definition['name'] || ($name !== '_id_' && $keys === $definition['keys'])) {
                $collection->dropIndex($name);
            }
        }

        $collection->createIndex($definition['keys'], [
            'name' => $definition['name'],
            'collation' => $definition['collation'],
        ]);
    }

    /** @return array<string, int> */
    private function arrayFrom(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }
};
