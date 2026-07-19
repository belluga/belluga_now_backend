<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\UpdateResult;

class AccountProfileRegistryDefaultUpserter
{
    /**
     * @param  array<string, mixed>  $entry
     */
    public function ensureDefault(
        array $entry,
        UTCDateTime $now,
    ): AccountProfileRegistryDefaultUpsertOutcome {
        $type = trim((string) ($entry['type'] ?? ''));
        if ($type === '') {
            throw new InvalidArgumentException('Registry defaults require a non-empty type.');
        }

        try {
            $result = $this->performUpsert($this->collection(), $type, $entry, $now);
        } catch (BulkWriteException $exception) {
            if (! $this->isExpectedTypeDuplicate($exception, $type) || ! $this->typeExists($type)) {
                throw $exception;
            }

            return AccountProfileRegistryDefaultUpsertOutcome::ConvergedAfterDuplicate;
        }

        return $result->getUpsertedCount() > 0
            ? AccountProfileRegistryDefaultUpsertOutcome::Inserted
            : AccountProfileRegistryDefaultUpsertOutcome::Existing;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function performUpsert(
        Collection $collection,
        string $type,
        array $entry,
        UTCDateTime $now,
    ): UpdateResult {
        $document = $entry;
        $document['type'] = $type;
        $document['created_at'] = $now;
        $document['updated_at'] = $now;

        return $collection->updateOne(
            ['type' => $type],
            ['$setOnInsert' => $document],
            ['upsert' => true],
        );
    }

    protected function isExpectedTypeDuplicate(BulkWriteException $exception, string $type): bool
    {
        $message = $exception->getMessage();
        if ((int) $exception->getCode() !== 11000 && ! str_contains($message, 'E11000')) {
            return false;
        }

        $quotedType = preg_quote($type, '/');

        return preg_match(
            '/\\bdup key:\\s*\\{\\s*type:\\s*"'.$quotedType.'"\\s*\\}/',
            $message,
        ) === 1;
    }

    private function collection(): Collection
    {
        return DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');
    }

    private function typeExists(string $type): bool
    {
        return $this->collection()->findOne(
            ['type' => $type],
            ['projection' => ['_id' => 1]],
        ) !== null;
    }
}
