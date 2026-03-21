<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

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
     * @param  array<int, string>  $artistProfileIds
     * @return array<int, array<string, mixed>>
     */
    public function resolveArtistsByProfileIds(array $artistProfileIds): array;

    /**
     * @return array<int, string>
     */
    public function listProfileIdsForAccount(string $accountId): array;

    public function accountOwnsProfile(string $accountId, string $profileId): bool;

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function listPartyCandidates(?string $search = null, int $perTypeLimit = 50): array;
}
