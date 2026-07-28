<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Collection;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasCollection('account_profile_types')) {
            Schema::create('account_profile_types', function (Blueprint $collection) {
                $collection->unique('type');
                $collection->index(['capabilities.is_favoritable' => 1]);
                $collection->index(['capabilities.is_poi_enabled' => 1]);
                $collection->index(['created_at' => -1, 'updated_at' => -1]);
                $collection->index(
                    ['capabilities.is_queryable' => 1, 'type' => 1],
                    options: ['name' => 'idx_account_profile_types_queryable_candidates_v1']
                );
            });

            return;
        }

        if ($this->hasQueryableCandidateIndex()) {
            return;
        }

        Schema::table('account_profile_types', function (Blueprint $collection): void {
            $collection->index(
                ['capabilities.is_queryable' => 1, 'type' => 1],
                options: ['name' => 'idx_account_profile_types_queryable_candidates_v1']
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_profile_types');
    }

    private function hasQueryableCandidateIndex(): bool
    {
        /** @var Collection<array<string, mixed>> $collection */
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');

        foreach ($collection->listIndexes() as $index) {
            $keys = $this->arrayFrom($index->getKey());

            if ($keys === ['capabilities.is_queryable' => 1, 'type' => 1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    private function arrayFrom(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }
};
