<?php

declare(strict_types=1);

namespace App\DataObjects\Settings;

class ProfileTypeRegistrySettings
{
    /**
     * @param array<int, array<string, mixed>> $entries
     */
    public function __construct(
        public array $entries,
    ) {
    }

    public static function fromValue(mixed $value): self
    {
        $payload = SettingsPayload::toArray($value);

        $entries = array_values(array_filter($payload, static fn ($entry): bool => is_array($entry)));

        return new self($entries);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->entries;
    }
}
