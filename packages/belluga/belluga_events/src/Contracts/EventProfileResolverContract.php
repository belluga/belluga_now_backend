<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface EventProfileResolverContract
{
    /**
     * @return array{
     *   venue: array<string, mixed>,
     *   location: array<string, mixed>
     * }
     */
    public function resolvePhysicalHostByProfileId(string $profileId): array;

    /**
     * @param  array<int, string>  $profileIds
     * @return array<int, array<string, mixed>>
     */
    public function resolveEventPartyProfilesByIds(array $profileIds): array;

    /**
     * @return array<int, string>
     */
    public function listProfileIdsForAccount(string $accountId): array;

    public function accountOwnsProfile(string $accountId, string $profileId): bool;

    public function paginateAccountProfileCandidates(
        string $candidateType,
        ?string $search = null,
        int $page = 1,
        int $perPage = 15,
        ?string $accountId = null
    ): LengthAwarePaginator;
}
