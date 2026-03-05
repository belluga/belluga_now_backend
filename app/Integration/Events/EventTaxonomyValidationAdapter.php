<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Application\Taxonomies\TaxonomyValidationService;
use Belluga\Events\Contracts\EventTaxonomyValidationContract;

class EventTaxonomyValidationAdapter implements EventTaxonomyValidationContract
{
    public function __construct(
        private readonly TaxonomyValidationService $taxonomyValidationService
    ) {
    }

    public function assertTermsAllowedForEvent(array $terms): void
    {
        $this->taxonomyValidationService->assertTermsAllowedForEvent($terms);
    }
}
