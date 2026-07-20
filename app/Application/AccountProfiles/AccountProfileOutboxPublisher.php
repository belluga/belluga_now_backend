<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONDocument;
use RuntimeException;

final class AccountProfileOutboxPublisher
{
    private const OUTBOX_COLLECTION = 'account_profile_outbox';

    private const RECEIPTS_COLLECTION = 'account_profile_command_receipts';

    /** @param array<string, mixed> $attributes */
    public function fingerprintForUpdate(
        string $profileId,
        array $attributes,
        array $supplement = [],
    ): string {
        return hash('sha256', json_encode([
            'operation' => 'upsert',
            'profile_id' => $profileId,
            'attributes' => $this->canonicalize($attributes),
            'supplement' => $this->canonicalize($supplement),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $supplement
     */
    public function fingerprintForCreate(array $payload, array $supplement = []): string
    {
        return hash('sha256', json_encode([
            'operation' => 'create',
            'payload' => $this->canonicalize($payload),
            'supplement' => $this->canonicalize($supplement),
        ], JSON_THROW_ON_ERROR));
    }

    public function fingerprintForLifecycle(string $profileId, string $operation): string
    {
        return hash('sha256', json_encode([
            'operation' => $operation,
            'profile_id' => trim($profileId),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    public function receipt(
        AccountProfileTransactionContext $context,
        string $commandId,
    ): ?array {
        $receipt = $context
            ->collection(self::RECEIPTS_COLLECTION)
            ->findOne(['_id' => $commandId], $context->rawOptions());

        return $this->documentToArray($receipt);
    }

    public function assertReceiptMatches(array $receipt, string $fingerprint): void
    {
        if (hash_equals((string) ($receipt['payload_fingerprint'] ?? ''), $fingerprint)) {
            return;
        }

        throw ValidationException::withMessages([
            'X-Request-Id' => ['This request id was already used with a different Account Profile command.'],
        ]);
    }

    /** @return array<string, mixed>|null */
    public function committedReceipt(string $commandId): ?array
    {
        $receipt = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection(self::RECEIPTS_COLLECTION)
            ->findOne(['_id' => $commandId]);

        return $this->documentToArray($receipt);
    }

    public function recordUpsert(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        string $commandId,
        string $fingerprint,
    ): string {
        $profileId = trim((string) $profile->getKey());
        $aggregateRevision = (int) $profile->getAttribute('aggregate_revision');
        if ($profileId === '' || $aggregateRevision < 1) {
            throw new RuntimeException('Account Profile outbox requires a persisted aggregate revision.');
        }

        $eventId = "{$profileId}:{$aggregateRevision}:upsert";
        $timestamp = new UTCDateTime((int) now()->getTimestampMs());
        $options = $context->rawOptions();

        $context->collection(self::OUTBOX_COLLECTION)->insertOne([
            '_id' => $eventId,
            'schema_version' => 1,
            'event_id' => $eventId,
            'command_id' => $commandId,
            'profile_id' => $profileId,
            'aggregate_revision' => $aggregateRevision,
            'operation' => 'upsert',
            'operation_rank' => 0,
            'occurred_at' => $timestamp,
            'delivery_state' => 'pending',
            'delivery_attempts' => 0,
            'projection' => $this->projection($profile),
            'created_at' => $timestamp,
        ], $options);

        $context->collection(self::RECEIPTS_COLLECTION)->insertOne([
            '_id' => $commandId,
            'command_id' => $commandId,
            'payload_fingerprint' => $fingerprint,
            'profile_id' => $profileId,
            'aggregate_revision' => $aggregateRevision,
            'outbox_event_id' => $eventId,
            'created_at' => $timestamp,
        ], $options);

        return $eventId;
    }

    public function recordTombstone(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        string $commandId,
        string $fingerprint,
    ): string {
        $profileId = trim((string) $profile->getKey());
        $aggregateRevision = (int) $profile->getAttribute('aggregate_revision');
        if ($profileId === '' || $aggregateRevision < 0) {
            throw new RuntimeException('Account Profile tombstone requires a persisted aggregate revision.');
        }

        $eventId = "{$profileId}:{$aggregateRevision}:tombstone";
        $timestamp = new UTCDateTime((int) now()->getTimestampMs());
        $options = $context->rawOptions();

        $outbox = $context->collection(self::OUTBOX_COLLECTION);
        $existingEvent = $this->documentToArray($outbox->findOne(['_id' => $eventId], $options));
        if ($existingEvent === null) {
            $outbox->insertOne([
                '_id' => $eventId,
                'schema_version' => 1,
                'event_id' => $eventId,
                'command_id' => $commandId,
                'profile_id' => $profileId,
                'aggregate_revision' => $aggregateRevision,
                'operation' => 'tombstone',
                'operation_rank' => 1,
                'occurred_at' => $timestamp,
                'delivery_state' => 'pending',
                'delivery_attempts' => 0,
                'tombstone' => $this->tombstone($profile),
                'created_at' => $timestamp,
            ], $options);
        } elseif (
            (string) ($existingEvent['profile_id'] ?? '') !== $profileId
            || (int) ($existingEvent['aggregate_revision'] ?? -1) !== $aggregateRevision
            || (string) ($existingEvent['operation'] ?? '') !== 'tombstone'
        ) {
            throw new RuntimeException('Account Profile tombstone tuple conflicts with an existing outbox event.');
        }

        $context->collection(self::RECEIPTS_COLLECTION)->insertOne([
            '_id' => $commandId,
            'command_id' => $commandId,
            'payload_fingerprint' => $fingerprint,
            'profile_id' => $profileId,
            'aggregate_revision' => $aggregateRevision,
            'outbox_event_id' => $eventId,
            'created_at' => $timestamp,
        ], $options);

        return $eventId;
    }

    /** @return array<string, mixed> */
    private function projection(AccountProfile $profile): array
    {
        $updatedAt = $profile->updated_at;

        return [
            'profile_id' => (string) $profile->getKey(),
            'account_id' => (string) ($profile->account_id ?? ''),
            'created_by' => $profile->created_by,
            'created_by_type' => $profile->created_by_type,
            'profile_type' => (string) ($profile->profile_type ?? ''),
            'display_name' => (string) ($profile->display_name ?? ''),
            'slug' => $profile->slug,
            'visibility' => (string) ($profile->visibility ?? ''),
            'is_active' => (bool) ($profile->is_active ?? false),
            'bio' => $profile->bio,
            'location' => $profile->location,
            'taxonomy_terms' => $profile->taxonomy_terms,
            'contact_mode' => $profile->contact_mode,
            'contact_source_account_profile_id' => $profile->contact_source_account_profile_id,
            'contact_channels' => $profile->contact_channels,
            'avatar_url' => $profile->avatar_url,
            'cover_url' => $profile->cover_url,
            'source_checkpoint' => $updatedAt === null
                ? (int) now()->getTimestampMs()
                : (int) $updatedAt->getTimestampMs(),
        ];
    }

    /** @return array<string, mixed> */
    private function tombstone(AccountProfile $profile): array
    {
        return [
            'profile_id' => (string) $profile->getKey(),
            'account_id' => (string) ($profile->account_id ?? ''),
            'created_by' => $profile->created_by,
            'created_by_type' => $profile->created_by_type,
            'profile_type' => (string) ($profile->profile_type ?? ''),
        ];
    }

    /** @return array<string, mixed>|null */
    private function documentToArray(mixed $document): ?array
    {
        if ($document instanceof BSONDocument) {
            return $document->getArrayCopy();
        }

        return is_array($document) ? $document : null;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $normalized = [];
        foreach ($value as $key => $entry) {
            $normalized[$key] = $this->canonicalize($entry);
        }

        if (! $isList) {
            ksort($normalized);
        }

        return $normalized;
    }
}
