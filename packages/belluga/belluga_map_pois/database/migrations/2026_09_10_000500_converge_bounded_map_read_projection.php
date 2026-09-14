<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

return new class extends Migration
{
    private const TARGET_INDEX = 'idx_map_pois_location_active_v1';

    public function up(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $pois = $database->selectCollection('map_pois');
        $targetKey = ['location' => '2dsphere', 'is_active' => 1];
        $indexes = [];
        foreach ($pois->listIndexes() as $index) {
            $indexes[$index->getName()] = json_decode(
                json_encode($index->getKey(), JSON_THROW_ON_ERROR),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        }

        if (isset($indexes[self::TARGET_INDEX]) && $indexes[self::TARGET_INDEX] !== $targetKey) {
            throw new RuntimeException('tenant.map_pois.bounded_read_projection_v1 found same-name conflict');
        }
        foreach ($indexes as $name => $key) {
            if ($name !== self::TARGET_INDEX && $key === $targetKey) {
                throw new RuntimeException('tenant.map_pois.bounded_read_projection_v1 found same-key conflict: '.$name);
            }
        }

        $profiles = $database->selectCollection('account_profiles');
        $accounts = $database->selectCollection('accounts');
        foreach ($pois->find(['ref_type' => 'account_profile']) as $poi) {
            $profile = $this->findByFlexibleId($profiles, $poi['ref_id'] ?? null);
            $sourceActive = $profile !== null
                && (bool) ($profile['is_active'] ?? false)
                && ! isset($profile['deleted_at']);
            $account = $sourceActive
                ? $this->findByFlexibleId($accounts, $profile['account_id'] ?? null)
                : null;
            $published = $account !== null
                && (($account['publication']['status'] ?? null) === 'published')
                && ! isset($account['deleted_at']);

            $pois->updateOne(
                ['_id' => $poi['_id']],
                ['$set' => ['is_active' => $sourceActive && $published]],
            );
        }

        if (! isset($indexes[self::TARGET_INDEX])) {
            $pois->createIndex($targetKey, ['name' => self::TARGET_INDEX]);
        }
        foreach ($indexes as $name => $key) {
            if ($key === ['location' => '2dsphere']) {
                $pois->dropIndex($name);
            }
        }
    }

    public function down(): void {}

    private function findByFlexibleId(object $collection, mixed $id): ?object
    {
        $candidates = [];
        if ($id instanceof ObjectId) {
            $candidates[] = $id;
            $candidates[] = (string) $id;
        } else {
            $stringId = trim((string) $id);
            if ($stringId === '') {
                return null;
            }
            $candidates[] = $stringId;
            if (preg_match('/^[a-f0-9]{24}$/i', $stringId) === 1) {
                $candidates[] = new ObjectId($stringId);
            }
        }

        return $collection->findOne(['_id' => ['$in' => $candidates]]);
    }
};
