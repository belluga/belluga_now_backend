<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Support\RichText\RichTextReadCanonicalizer;
use Belluga\Events\Contracts\EventContentReadCanonicalizerContract;

final class EventContentReadCanonicalizerAdapter implements EventContentReadCanonicalizerContract
{
    public function __construct(private readonly RichTextReadCanonicalizer $canonicalizer) {}

    public function canonicalize(mixed $value, string $resourceId, string $field): string
    {
        return $this->canonicalizer->canonicalize(
            $value,
            true,
            'event',
            $resourceId,
            $field,
        );
    }
}
