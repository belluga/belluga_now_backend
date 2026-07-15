<?php

declare(strict_types=1);

namespace Belluga\ContactChannels\Contracts;

use Belluga\ContactChannels\Support\ContactChannelCapabilities;
use Belluga\ContactChannels\Support\ContactChannelLaunchResolution;

interface ContactChannelDefinitionContract
{
    public function type(): string;

    public function canonicalLabel(): string;

    public function canonicalIcon(): string;

    public function capabilities(): ContactChannelCapabilities;

    /**
     * Returns the canonical stored value, or null when the supplied value is invalid.
     */
    public function normalizeValue(string $value): ?string;

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function normalizeMetadata(array $metadata, string $field): array;

    public function resolveLaunch(string $value, ?string $prefilledMessage = null): ContactChannelLaunchResolution;
}
