<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventTemplateSnapshotReadContract
{
    /**
     * @return array<string, mixed>|null
     */
    public function findTemplateSnapshot(string $templateId): ?array;
}
