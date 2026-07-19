<?php

declare(strict_types=1);

use App\Application\AccountProfiles\AccountProfileContactChannelsService;
use App\Application\AccountProfiles\AccountProfileNameSearchKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Model\BSONDocument;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasCollection('account_profiles') || ! Schema::hasCollection('account_profile_types')) {
            return;
        }

        $database = DB::connection('tenant')->getDatabase();
        $this->backfillProfileSearchKeysAndContactModes($database->selectCollection('account_profiles'));
        $this->provisionTypeIndexes($database->selectCollection('account_profile_types'));
        $this->provisionProfileIndexes($database->selectCollection('account_profiles'));
    }

    public function down(): void
    {
        if (! Schema::hasCollection('account_profiles') || ! Schema::hasCollection('account_profile_types')) {
            return;
        }

        $database = DB::connection('tenant')->getDatabase();
        $this->dropIndexIfPresent($database->selectCollection('account_profiles'), 'idx_account_profiles_candidate_queryable_name_v1');
        $this->dropIndexIfPresent($database->selectCollection('account_profiles'), 'idx_account_profiles_candidate_contact_name_v1');
        $this->dropIndexIfPresent($database->selectCollection('account_profile_types'), 'idx_account_profile_types_candidate_queryable_v1');
        $this->dropIndexIfPresent($database->selectCollection('account_profile_types'), 'idx_account_profile_types_candidate_contact_capable_v1');
        $database->selectCollection('account_profile_types')->createIndex(
            ['capabilities.is_queryable' => 1, 'type' => 1],
            ['name' => 'idx_account_profile_types_queryable_candidates_v1'],
        );
        $database->selectCollection('account_profile_types')->createIndex(
            ['capabilities.has_contact_channels' => 1, 'type' => 1],
            ['name' => 'idx_account_profile_types_contact_channels_v1'],
        );
    }

    private function backfillProfileSearchKeysAndContactModes(\MongoDB\Collection $collection): void
    {
        $operations = [];
        $cursor = $collection->find([], [
            'projection' => [
                '_id' => 1,
                'display_name' => 1,
                'name_search_key' => 1,
                'contact_mode' => 1,
            ],
        ]);

        foreach ($cursor as $document) {
            $row = $this->documentToArray($document);
            $id = $row['_id'] ?? null;
            if ($id === null) {
                continue;
            }

            $nameSearchKey = AccountProfileNameSearchKey::fromDisplayName((string) ($row['display_name'] ?? ''));
            $contactMode = strtolower(trim((string) ($row['contact_mode'] ?? '')))
                === AccountProfileContactChannelsService::CONTACT_MODE_MIRRORED_ACCOUNT_PROFILE
                ? AccountProfileContactChannelsService::CONTACT_MODE_MIRRORED_ACCOUNT_PROFILE
                : AccountProfileContactChannelsService::CONTACT_MODE_OWN;
            if ((string) ($row['name_search_key'] ?? '') === $nameSearchKey
                && (string) ($row['contact_mode'] ?? '') === $contactMode) {
                continue;
            }

            $operations[] = [
                'updateOne' => [
                    ['_id' => $id],
                    ['$set' => [
                        'name_search_key' => $nameSearchKey,
                        'contact_mode' => $contactMode,
                    ]],
                ],
            ];

            if (count($operations) === 500) {
                $collection->bulkWrite($operations, ['ordered' => false]);
                $operations = [];
            }
        }

        if ($operations !== []) {
            $collection->bulkWrite($operations, ['ordered' => false]);
        }
    }

    private function provisionTypeIndexes(\MongoDB\Collection $collection): void
    {
        $this->dropIndexIfPresent($collection, 'idx_account_profile_types_queryable_candidates_v1');
        $this->dropIndexIfPresent($collection, 'idx_account_profile_types_contact_channels_v1');

        $options = ['collation' => ['locale' => 'simple']];
        $collection->createIndex(
            ['capabilities.is_queryable' => 1, 'type' => 1],
            [...$options, 'name' => 'idx_account_profile_types_candidate_queryable_v1'],
        );
        $collection->createIndex(
            ['capabilities.has_contact_channels' => 1, 'type' => 1],
            [...$options, 'name' => 'idx_account_profile_types_candidate_contact_capable_v1'],
        );
    }

    private function provisionProfileIndexes(\MongoDB\Collection $collection): void
    {
        $keys = ['profile_type' => 1, 'name_search_key' => 1, '_id' => 1];
        $collection->createIndex($keys, [
            'name' => 'idx_account_profiles_candidate_queryable_name_v1',
            'partialFilterExpression' => ['is_active' => true, 'deleted_at' => null],
            'collation' => ['locale' => 'simple'],
        ]);
        $collection->createIndex($keys, [
            'name' => 'idx_account_profiles_candidate_contact_name_v1',
            'partialFilterExpression' => [
                'is_active' => true,
                'deleted_at' => null,
                'contact_mode' => AccountProfileContactChannelsService::CONTACT_MODE_OWN,
            ],
            'collation' => ['locale' => 'simple'],
        ]);
    }

    private function dropIndexIfPresent(\MongoDB\Collection $collection, string $name): void
    {
        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() === $name) {
                $collection->dropIndex($name);

                return;
            }
        }
    }

    /** @return array<string, mixed> */
    private function documentToArray(mixed $document): array
    {
        if (is_array($document)) {
            return $document;
        }

        if ($document instanceof BSONDocument) {
            return $document->getArrayCopy();
        }

        if ($document instanceof \Traversable) {
            return iterator_to_array($document);
        }

        return [];
    }
};
