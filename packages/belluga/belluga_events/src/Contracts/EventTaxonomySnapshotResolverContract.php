<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventTaxonomySnapshotResolverContract
{
    /**
     * @param  array<int, array<string, mixed>>  $terms
     * @return array<int, array<string, string>>
     */
    public function resolve(array $terms): array;

    /**
     * @param  array<int, array<string, mixed>>  $terms
     * @return array<int, array<string, string>>
     */
    public function ensureSnapshots(array $terms): array;
}
