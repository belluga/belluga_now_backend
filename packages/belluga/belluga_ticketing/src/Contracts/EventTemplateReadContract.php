<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Contracts;

interface EventTemplateReadContract
{
    /**
     * @return array<string, mixed>|null
     */
    public function findTemplateSnapshot(string $templateId): ?array;
}
