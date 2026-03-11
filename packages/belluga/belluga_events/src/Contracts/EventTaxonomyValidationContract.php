<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventTaxonomyValidationContract
{
    /**
     * @param  array<int, array<string, mixed>>  $terms
     */
    public function assertTermsAllowedForEvent(array $terms): void;
}
