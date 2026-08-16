<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Models\Tenants\Account;

final class AccountPublicationStateService
{
    public const string DRAFT = 'draft';
    public const string PUBLISHED = 'published';

    /**
     * @return array{status:string,publish_at:null}
     */
    public function draftPublication(): array
    {
        return [
            'status' => self::DRAFT,
            'publish_at' => null,
        ];
    }

    /**
     * @return array{status:string,publish_at:null}
     */
    public function publishedPublication(): array
    {
        return [
            'status' => self::PUBLISHED,
            'publish_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function applyCreatePublication(array $payload): array
    {
        if (! array_key_exists('publication', $payload)) {
            $payload['publication'] = $this->publishedPublication();

            return $payload;
        }

        $payload['publication'] = $this->normalizePublication($payload['publication']);

        return $payload;
    }

    /**
     * @return array{status:string,publish_at:mixed}
     */
    public function normalizePublication(mixed $publication): array
    {
        $status = trim((string) data_get($publication, 'status', ''));

        if (! in_array($status, [
            self::DRAFT,
            self::PUBLISHED,
        ], true)) {
            $status = self::PUBLISHED;
        }

        return [
            'status' => $status,
            'publish_at' => null,
        ];
    }

    public function isPublished(mixed $publication): bool
    {
        return trim((string) data_get($publication, 'status', '')) === self::PUBLISHED;
    }

    /**
     * @param  array<int, string>  $accountIds
     * @return array<int, string>
     */
    public function publishedAccountIds(array $accountIds = []): array
    {
        $normalizedIds = array_values(array_unique(array_filter(array_map(
            static fn (string $id): string => trim($id),
            $accountIds,
        ), static fn (string $id): bool => $id !== '')));

        if ($normalizedIds === []) {
            return [];
        }

        $query = Account::query()->select(['_id', 'publication']);
        $query->whereIn('_id', $normalizedIds);

        return $query
            ->get()
            ->filter(fn (Account $account): bool => $this->isPublished(
                $account->getAttribute('publication')
            ))
            ->map(static fn (Account $account): string => (string) $account->getKey())
            ->values()
            ->all();
    }

    public function isAccountIdPublished(?string $accountId): bool
    {
        $normalizedId = trim((string) $accountId);
        if ($normalizedId === '') {
            return false;
        }

        $account = Account::query()
            ->select(['_id', 'publication'])
            ->where('_id', $normalizedId)
            ->first();

        return $account instanceof Account
            && $this->isPublished($account->getAttribute('publication'));
    }
}
