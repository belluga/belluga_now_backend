<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use Illuminate\Support\Facades\DB;
use LogicException;
use MongoDB\Collection;
use MongoDB\Model\IndexInfo;
use Traversable;

final class AccountProfileRegistrySyncIndexPrecondition
{
    public function assertCompatibleTypeIndex(): void
    {
        foreach ($this->collection()->listIndexes() as $index) {
            if ($index instanceof IndexInfo && $this->isCompatibleTypeIndex($index)) {
                return;
            }
        }

        throw new LogicException(
            'Profile registry sync requires a compatible unique type index before repair.',
        );
    }

    private function isCompatibleTypeIndex(IndexInfo $index): bool
    {
        if (! $index->isUnique() || $index->isSparse() || $index->getKey() !== ['type' => 1]) {
            return false;
        }

        if (isset($index['partialFilterExpression'])) {
            return false;
        }

        if (! isset($index['collation'])) {
            return true;
        }

        return $this->arrayFrom($index['collation']) === ['locale' => 'simple'];
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayFrom(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof Traversable) {
            return iterator_to_array($value);
        }

        return is_object($value) ? (array) $value : [];
    }

    private function collection(): Collection
    {
        return DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');
    }
}
