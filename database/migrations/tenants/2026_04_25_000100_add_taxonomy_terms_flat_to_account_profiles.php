<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        $collection = DB::connection('tenant')->getCollection('account_profiles');
        foreach ($collection->find([], ['projection' => ['taxonomy_terms' => 1]]) as $profile) {
            $terms = $profile['taxonomy_terms'] ?? [];
            if ($terms instanceof Traversable) {
                $terms = iterator_to_array($terms);
            }
            if (! is_array($terms)) {
                $terms = [];
            }

            $flat = [];
            foreach ($terms as $term) {
                if ($term instanceof Traversable) {
                    $term = iterator_to_array($term);
                }
                if (! is_array($term)) {
                    continue;
                }

                $type = trim((string) ($term['type'] ?? ''));
                $value = trim((string) ($term['value'] ?? ''));
                if ($type !== '' && $value !== '') {
                    $flat[] = "{$type}:{$value}";
                }
            }

            $collection->updateOne(
                ['_id' => $profile['_id']],
                ['$set' => ['taxonomy_terms_flat' => array_values(array_unique($flat))]]
            );
        }

        Schema::table('account_profiles', static function (Blueprint $collection): void {
            $collection->index(
                [
                    'visibility' => 1,
                    'is_active' => 1,
                    'profile_type' => 1,
                    'taxonomy_terms_flat' => 1,
                    'deleted_at' => 1,
                    'created_at' => -1,
                    '_id' => -1,
                ],
                options: [
                    'name' => 'idx_account_profiles_public_taxonomy_flat_v1',
                ]
            );
        });
    }

    public function down(): void
    {
        try {
            DB::connection('tenant')
                ->getCollection('account_profiles')
                ->dropIndex('idx_account_profiles_public_taxonomy_flat_v1');
        } catch (Throwable) {
            // Index may not exist on partially migrated local/test databases.
        }
    }
};
