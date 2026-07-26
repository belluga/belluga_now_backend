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
     * @return array<string, array{
     *   venue: array<string, mixed>,
     *   location: array<string, mixed>
     * }>
     */
    public function resolvePhysicalHostsByProfileIds(array $profileIds): array;

    /**
     * @param  array<int, string>  $profileIds
     * @return array<int, array<string, mixed>>
     */
    public function resolveEventPartyProfilesByIds(array $profileIds): array;

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array<string, mixed>>
     */
    public function resolveExistingEventPartyProfilesByIds(array $profileIds): array;

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array{
     *   id: string,
     *   label: ?string,
     *   search_key: ?string,
     *   profile_type: ?string,
     *   category: ?string,
     *   taxonomy_terms_flat: array<int, string>,
     *   slug: ?string,
     *   avatar_url: ?string,
     *   cover_url: ?string
     * }>
     */
    public function resolveNestedAccountProfileSnapshotsByIds(array $profileIds): array;

    /**
     * @return array<int, string>
     */
    public function listProfileIdsForAccount(string $accountId): array;

    /**
     * @param  array<int, string>  $profileIds
     * @return array<int, string>
     */
    public function resolveAccountIdsForProfileIds(array $profileIds): array;

    /**
     * @param  array<int, string>  $types
     * @return array<string, string>
     */
    public function resolveProfileTypePluralLabelsByTypes(array $types): array;

    public function accountOwnsProfile(string $accountId, string $profileId): bool;

    public function paginateAccountProfileCandidates(
        string $candidateType,
        ?string $search = null,
        int $page = 1,
        int $perPage = 15,
        ?string $accountId = null,
        ?string $baseUrl = null,
        ?string $profileType = null,
    ): LengthAwarePaginator;

    public function isProfileTypeQueryable(string $profileType): bool;

    public function isProfileTypePubliclyNavigable(string $profileType): bool;
}
