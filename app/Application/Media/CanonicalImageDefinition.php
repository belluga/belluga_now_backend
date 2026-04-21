<?php

declare(strict_types=1);

namespace App\Application\Media;

final readonly class CanonicalImageDefinition
{
    /**
     * @param  array<int, string>  $storageCandidates
     * @param  array<int, string>  $legacyPublicPaths
     */
    public function __construct(
        public string $canonicalPublicPath,
        public array $storageCandidates,
        public array $legacyPublicPaths = [],
    ) {}
}
