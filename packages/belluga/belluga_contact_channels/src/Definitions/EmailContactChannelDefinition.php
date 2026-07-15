<?php

declare(strict_types=1);

namespace Belluga\ContactChannels\Definitions;

use Belluga\ContactChannels\ContactChannelValidationException;
use Belluga\ContactChannels\Contracts\ContactChannelDefinitionContract;
use Belluga\ContactChannels\Support\ContactChannelCapabilities;
use Belluga\ContactChannels\Support\ContactChannelLaunchResolution;

final class EmailContactChannelDefinition implements ContactChannelDefinitionContract
{
    public function type(): string
    {
        return 'email';
    }

    public function canonicalLabel(): string
    {
        return 'Email';
    }

    public function canonicalIcon(): string
    {
        return 'email';
    }

    public function capabilities(): ContactChannelCapabilities
    {
        return new ContactChannelCapabilities(true, true, false, false, true, 0, 0, 0);
    }

    public function normalizeValue(string $value): ?string
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || mb_strlen($normalized) > 255 || ! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $normalized;
    }

    public function normalizeMetadata(array $metadata, string $field): array
    {
        if ($metadata !== []) {
            throw new ContactChannelValidationException($field, 'Metadata is not supported for email contact channels.');
        }

        return [];
    }

    public function resolveLaunch(string $value, ?string $prefilledMessage = null): ContactChannelLaunchResolution
    {
        $normalized = $this->normalizeValue($value);

        return new ContactChannelLaunchResolution(
            $normalized === null ? null : 'mailto:'.rawurlencode($normalized),
        );
    }
}
