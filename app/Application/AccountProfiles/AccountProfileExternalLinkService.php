<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Exceptions\AccountProfileExternalLinksCapabilityDisabledException;
use App\Models\Tenants\AccountProfile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountProfileExternalLinkService
{
    public function __construct(
        private readonly AccountProfileExternalLinkRegistry $links,
        private readonly AccountProfileRegistryService $profileTypes,
        private readonly AccountProfileManagementService $profiles,
    ) {}

    public function currentLimit(?AccountProfile $profile = null): int
    {
        return $this->links->currentLimit($profile);
    }

    public function isAllowedForRead(AccountProfile $profile): bool
    {
        return $this->profileTypes->hasExternalLinksAuthoritatively((string) $profile->profile_type);
    }

    /** @return list<array{id:string,type:string,url:string,label?:string}> */
    public function formatForRead(AccountProfile $profile): array
    {
        return $this->links->normalizeStored(
            $this->rawExternalLinks($profile),
            $this->currentLimit($profile),
        );
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $auditAttributes */
    public function create(AccountProfile $profile, array $payload, ?string $commandId = null, array $auditAttributes = []): AccountProfile
    {
        $itemId = $this->createItemId($profile, $commandId);

        return $this->change($profile, function (array $items) use ($payload, $itemId, $profile): array {
            $normalized = $this->links->normalizeForWrite($payload);
            foreach ($items as $index => $item) {
                if ($item['type'] === $normalized['type']) {
                    if (hash_equals($item['id'], $itemId)) {
                        $items[$index] = ['id' => $itemId, ...$normalized];

                        return $items;
                    }
                    $this->fail('type', 'Only one external link of each type is allowed.');
                }
            }
            if (count($items) >= $this->links->currentLimit($profile)) {
                $this->fail('external_links_limit', 'The external links limit has been reached.');
            }

            $items[] = ['id' => $itemId, ...$normalized];

            return $items;
        }, $commandId, $auditAttributes);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $auditAttributes */
    public function update(AccountProfile $profile, string $externalLinkId, array $payload, ?string $commandId = null, array $auditAttributes = []): AccountProfile
    {
        return $this->change($profile, function (array $items) use ($externalLinkId, $payload): array {
            $index = $this->indexForId($items, $externalLinkId);
            $current = $items[$index];
            $next = ['type' => $current['type'], 'url' => $payload['url'] ?? null];
            if ($current['type'] === 'website') {
                $next['label'] = array_key_exists('label', $payload) ? $payload['label'] : ($current['label'] ?? null);
            } elseif (array_key_exists('label', $payload)) {
                $next['label'] = $payload['label'];
            }
            $normalized = $this->links->normalizeForWrite($next);
            $items[$index] = ['id' => $current['id'], ...$normalized];

            return $items;
        }, $commandId, $auditAttributes);
    }

    /** @param array<string,mixed> $auditAttributes */
    public function delete(AccountProfile $profile, string $externalLinkId, ?string $commandId = null, array $auditAttributes = []): AccountProfile
    {
        return $this->change($profile, function (array $items) use ($externalLinkId, $commandId): array {
            $index = $this->nullableIndexForId($items, $externalLinkId);
            if ($index === null) {
                if ($this->profiles->hasCommittedCommand($commandId)) {
                    return $items;
                }
                abort(404);
            }
            array_splice($items, $index, 1);

            return $items;
        }, $commandId, $auditAttributes);
    }

    /**
     * @param  callable(list<array{id:string,type:string,url:string,label?:string}>):list<array{id:string,type:string,url:string,label?:string}>  $compose
     * @param  array<string,mixed>  $auditAttributes
     */
    private function change(AccountProfile $profile, callable $compose, ?string $commandId, array $auditAttributes): AccountProfile
    {
        $snapshot = AccountProfile::query()->findOrFail((string) $profile->getKey());
        if (! $this->profileTypes->hasExternalLinksAuthoritatively((string) $snapshot->profile_type)) {
            throw AccountProfileExternalLinksCapabilityDisabledException::create();
        }

        $limit = $this->links->currentLimit($snapshot);
        $items = $compose($this->links->normalizeStored($this->rawExternalLinks($snapshot), $limit));
        $items = $this->links->normalizeStored($items, $limit);

        return $this->profiles->update($snapshot, [
            ...$auditAttributes,
            'external_links' => $items,
            'aggregate_revision' => max(0, (int) ($snapshot->aggregate_revision ?? 0)),
        ], commandId: $commandId);
    }

    private function rawExternalLinks(AccountProfile $profile): mixed
    {
        return $profile->getAttributes()['external_links'] ?? [];
    }

    /** @param list<array{id:string,type:string,url:string,label?:string}> $items */
    private function indexForId(array $items, string $externalLinkId): int
    {
        $index = $this->nullableIndexForId($items, $externalLinkId);
        if ($index !== null) {
            return $index;
        }

        abort(404);
    }

    /** @param list<array{id:string,type:string,url:string,label?:string}> $items */
    private function nullableIndexForId(array $items, string $externalLinkId): ?int
    {
        foreach ($items as $index => $item) {
            if (hash_equals($item['id'], trim($externalLinkId))) {
                return $index;
            }
        }

        return null;
    }

    private function createItemId(AccountProfile $profile, ?string $commandId): string
    {
        $normalizedCommandId = trim((string) $commandId);
        if ($normalizedCommandId === '') {
            return Str::lower((string) Str::ulid());
        }

        return substr(hash(
            'sha256',
            'account-profile-external-link|'.(string) $profile->getKey().'|'.$normalizedCommandId,
        ), 0, 26);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
