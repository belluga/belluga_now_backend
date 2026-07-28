<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use Illuminate\Support\Facades\DB;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use RuntimeException;

class AccountProfileRegistrySyncIndexPrecondition
{
    public function assertCompatibleTypeIndex(): void
    {
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');

        foreach ($collection->listIndexes() as $index) {
            if ($index->getKey() !== ['type' => 1]) {
                continue;
            }

            $unique = (bool) ($index['unique'] ?? false);
            $sparse = (bool) ($index['sparse'] ?? false);
            $partial = $this->toArray($index['partialFilterExpression'] ?? null);
            $collation = $this->toArray($index['collation'] ?? null);
            $locale = trim((string) ($collation['locale'] ?? ''));

            if ($unique && ! $sparse && $partial === [] && ($collation === [] || $locale === 'simple')) {
                return;
            }

            throw new RuntimeException('account_profile_types requires a unique simple {type:1} index without sparse or partial options before additive sync can run.');
        }

        throw new RuntimeException('account_profile_types requires a unique simple {type:1} index before additive sync can run.');
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }
}
