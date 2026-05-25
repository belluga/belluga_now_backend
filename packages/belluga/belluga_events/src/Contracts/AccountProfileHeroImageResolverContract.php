<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface AccountProfileHeroImageResolverContract
{
    /**
     * Resolve account-profile hero imagery from an already-loaded payload.
     *
     * @param  array<string, mixed>  $profilePayload
     */
    public function resolveFromPayload(array $profilePayload, bool $allowTypeVisualFallback = false): ?string;
}
