<?php

declare(strict_types=1);

namespace Belluga\ContactChannels;

use Belluga\ContactChannels\Registry\ContactChannelDefinitionRegistry;
use Belluga\ContactChannels\Support\ContactChannelIdentifierGeneratorContract;

final class ContactChannelCollectionNormalizer
{
    public const MAX_CHANNELS = 20;

    public function __construct(
        private readonly ContactChannelDefinitionRegistry $definitions,
        private readonly ContactChannelIdentifierGeneratorContract $identifierGenerator,
    ) {}

    /**
     * @param array<int, mixed> $incomingChannels
     * @param array<int, array<string, mixed>> $storedChannels
     */
    public function normalizeForWrite(array $incomingChannels, array $storedChannels): ContactChannelNormalizationResult
    {
        if (count($incomingChannels) > self::MAX_CHANNELS) {
            throw new ContactChannelValidationException('contact_channels', 'Contact channels exceed the maximum of 20.');
        }

        $this->assertRepeatabilityAndTitles($incomingChannels);
        $storedById = $this->storedById($storedChannels);
        $channels = [];
        $draftKeyToChannelId = [];
        $seenIds = [];

        foreach ($incomingChannels as $index => $rawChannel) {
            $field = "contact_channels.{$index}";
            if (! is_array($rawChannel)) {
                throw new ContactChannelValidationException($field, 'Contact channel must be an object.');
            }

            $persistedId = $this->nullableString($rawChannel['id'] ?? null);
            $type = strtolower(trim((string) ($rawChannel['type'] ?? '')));
            if ($type === '') {
                throw new ContactChannelValidationException("{$field}.type", 'Contact channel type is required.');
            }
            $definition = $this->definitions->require($type);

            if ($persistedId !== null) {
                if (! isset($storedById[$persistedId])) {
                    throw new ContactChannelValidationException("{$field}.id", 'Persisted contact channel ids are server-owned and must already belong to this profile.');
                }
                if (($storedById[$persistedId]['type'] ?? null) !== $type) {
                    throw new ContactChannelValidationException("{$field}.type", 'The type of an existing contact channel is immutable.');
                }
                $channelId = $persistedId;
            } else {
                $draftKey = $this->nullableString($rawChannel['draft_key'] ?? null);
                if ($draftKey === null) {
                    throw new ContactChannelValidationException("{$field}.draft_key", 'New contact channels require a draft_key.');
                }
                if (isset($draftKeyToChannelId[$draftKey])) {
                    throw new ContactChannelValidationException("{$field}.draft_key", 'New contact channel draft keys must be unique.');
                }

                $channelId = $this->nextUniqueIdentifier($seenIds, $storedById);
                $draftKeyToChannelId[$draftKey] = $channelId;
            }

            if (isset($seenIds[$channelId])) {
                throw new ContactChannelValidationException("{$field}.id", 'Contact channel ids must be unique within the profile.');
            }
            $seenIds[$channelId] = true;

            $value = $definition->normalizeValue(trim((string) ($rawChannel['value'] ?? '')));
            if ($value === null) {
                throw new ContactChannelValidationException("{$field}.value", "{$definition->canonicalLabel()} contact channel is not resolvable safely.");
            }

            $metadata = $definition->normalizeMetadata(
                $this->metadata($rawChannel['metadata'] ?? []),
                "{$field}.metadata",
            );
            $channel = ['id' => $channelId, 'type' => $type, 'value' => $value];
            $title = $this->nullableString($rawChannel['title'] ?? null);
            if ($title !== null) {
                $channel['title'] = $title;
            }
            if ($metadata !== []) {
                $channel['metadata'] = $metadata;
            }

            $channels[] = $channel;
        }

        return new ContactChannelNormalizationResult($channels, $draftKeyToChannelId);
    }

    /** @param array<int, mixed> $incomingChannels */
    private function assertRepeatabilityAndTitles(array $incomingChannels): void
    {
        $counts = [];
        $definitions = [];

        foreach ($incomingChannels as $index => $rawChannel) {
            if (! is_array($rawChannel)) {
                throw new ContactChannelValidationException("contact_channels.{$index}", 'Contact channel must be an object.');
            }

            $type = strtolower(trim((string) ($rawChannel['type'] ?? '')));
            if ($type === '') {
                throw new ContactChannelValidationException("contact_channels.{$index}.type", 'Contact channel type is required.');
            }
            $definitions[$type] = $this->definitions->require($type);
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        foreach ($counts as $type => $count) {
            $definition = $definitions[$type];
            if (! $definition->capabilities()->repeatable && $count > 1) {
                throw new ContactChannelValidationException('contact_channels', "{$definition->canonicalLabel()} contact channels are not repeatable.");
            }
            if ($count <= 1) {
                continue;
            }

            foreach ($incomingChannels as $index => $rawChannel) {
                if (strtolower(trim((string) ($rawChannel['type'] ?? ''))) === $type
                    && $this->nullableString($rawChannel['title'] ?? null) === null) {
                    throw new ContactChannelValidationException("contact_channels.{$index}.title", 'A title is required when a contact channel type repeats.');
                }
            }
        }
    }

    /** @param array<int, array<string, mixed>> $storedChannels @return array<string, array<string, mixed>> */
    private function storedById(array $storedChannels): array
    {
        $result = [];
        foreach ($storedChannels as $channel) {
            $id = $this->nullableString($channel['id'] ?? null);
            if ($id !== null) {
                $result[$id] = $channel;
            }
        }

        return $result;
    }

    /** @param array<string, bool> $seenIds @param array<string, array<string, mixed>> $storedById */
    private function nextUniqueIdentifier(array $seenIds, array $storedById): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $id = trim($this->identifierGenerator->generate());
            if ($id !== '' && ! isset($seenIds[$id]) && ! isset($storedById[$id])) {
                return $id;
            }
        }

        throw new \RuntimeException('Unable to generate a unique contact channel identifier.');
    }

    /** @return array<string, mixed> */
    private function metadata(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw)) {
            throw new ContactChannelValidationException('contact_channels.metadata', 'Contact channel metadata must be an object.');
        }

        return $raw;
    }

    private function nullableString(mixed $raw): ?string
    {
        $value = is_string($raw) || is_numeric($raw) ? trim((string) $raw) : '';

        return $value === '' ? null : $value;
    }
}
