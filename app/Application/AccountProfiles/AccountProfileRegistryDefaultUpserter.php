<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\UpdateResult;

class AccountProfileRegistryDefaultUpserter
{
    /**
     * @param  array<string, mixed>  $entry
     */
    public function ensureDefault(array $entry, UTCDateTime $now): void
    {
        $type = trim((string) ($entry['type'] ?? ''));
        if ($type === '') {
            return;
        }

        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');

        try {
            $result = $this->performUpsert($collection, $type, $entry, $now);
        } catch (BulkWriteException $exception) {
            if (! $this->isExpectedTypeDuplicate($exception, $type) || ! $this->typeExists($collection, $type)) {
                throw $exception;
            }

            AccountProfileTypeSetProvider::bumpRevision();

            return;
        }

        if ($this->upsertInsertedDocument($result)) {
            AccountProfileTypeSetProvider::bumpRevision();
        }
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
        return $collection->updateOne(
            ['type' => $type],
            ['$setOnInsert' => array_merge($entry, [
                'type' => $type,
                'created_at' => $now,
                'updated_at' => $now,
            ])],
            ['upsert' => true],
        );
    }

    protected function isExpectedTypeDuplicate(BulkWriteException $exception, string $type): bool
    {
        $message = $exception->getMessage();
        $quotedType = preg_quote($type, '/');

        return preg_match('/dup key:\s*\{\s*type:\s*"'.$quotedType.'"\s*\}/i', $message) === 1;
    }

    private function typeExists(Collection $collection, string $type): bool
    {
        return $collection->countDocuments(['type' => $type], ['limit' => 1]) > 0;
    }

    private function upsertInsertedDocument(UpdateResult $result): bool
    {
        return $result->getUpsertedCount() > 0 || $result->getUpsertedId() !== null;
    }
}
