<?php

declare(strict_types=1);

namespace Belluga\ContactChannels\Definitions;

use Belluga\ContactChannels\ContactChannelValidationException;
use Belluga\ContactChannels\Contracts\ContactChannelDefinitionContract;
use Belluga\ContactChannels\Support\ContactChannelCapabilities;
use Belluga\ContactChannels\Support\ContactChannelLaunchResolution;

final class WhatsAppContactChannelDefinition implements ContactChannelDefinitionContract
{
    private const MAX_INITIAL_MESSAGES = 20;

    private const MAX_INITIAL_MESSAGE_CTA_LENGTH = 255;

    private const MAX_INITIAL_MESSAGE_LENGTH = 1000;

    public function type(): string
    {
        return 'whatsapp';
    }

    public function canonicalLabel(): string
    {
        return 'WhatsApp';
    }

    public function canonicalIcon(): string
    {
        return 'whatsapp';
    }

    public function capabilities(): ContactChannelCapabilities
    {
        return new ContactChannelCapabilities(
            true,
            true,
            true,
            true,
            true,
            self::MAX_INITIAL_MESSAGES,
            self::MAX_INITIAL_MESSAGE_CTA_LENGTH,
            self::MAX_INITIAL_MESSAGE_LENGTH,
        );
    }

    public function normalizeValue(string $value): ?string
    {
        $normalized = trim($value);

        return $normalized !== '' && mb_strlen($normalized) <= 255 && $this->targetDigits($normalized) !== null
            ? $normalized
            : null;
    }

    public function normalizeMetadata(array $metadata, string $field): array
    {
        if ($metadata === []) {
            return [];
        }

        if (array_diff(array_keys($metadata), ['initial_messages']) !== []) {
            throw new ContactChannelValidationException($field, 'WhatsApp metadata contains unsupported fields.');
        }

        $rawMessages = $metadata['initial_messages'] ?? [];
        if (! is_array($rawMessages)) {
            throw new ContactChannelValidationException("{$field}.initial_messages", 'WhatsApp initial messages must be an array.');
        }
        if (count($rawMessages) > self::MAX_INITIAL_MESSAGES) {
            throw new ContactChannelValidationException("{$field}.initial_messages", 'WhatsApp initial messages exceed the configured limit.');
        }

        $messages = [];
        $seenIds = [];
        foreach ($rawMessages as $index => $rawMessage) {
            if (! is_array($rawMessage)) {
                throw new ContactChannelValidationException("{$field}.initial_messages.{$index}", 'Initial message must be an object.');
            }

            $id = trim((string) ($rawMessage['id'] ?? ''));
            $cta = trim((string) ($rawMessage['cta'] ?? ''));
            $message = trim((string) ($rawMessage['mensagem'] ?? ''));
            if ($id === '' || $cta === '' || $message === '') {
                throw new ContactChannelValidationException("{$field}.initial_messages.{$index}", 'Initial message id, CTA, and message are required.');
            }
            if (mb_strlen($cta) > self::MAX_INITIAL_MESSAGE_CTA_LENGTH) {
                throw new ContactChannelValidationException("{$field}.initial_messages.{$index}.cta", 'Initial message CTA exceeds the configured limit.');
            }
            if (mb_strlen($message) > self::MAX_INITIAL_MESSAGE_LENGTH) {
                throw new ContactChannelValidationException("{$field}.initial_messages.{$index}.mensagem", 'Initial message text exceeds the configured limit.');
            }
            if (isset($seenIds[$id])) {
                throw new ContactChannelValidationException("{$field}.initial_messages.{$index}.id", 'Initial message ids must be unique within its WhatsApp channel.');
            }

            $seenIds[$id] = true;
            $messages[] = ['id' => $id, 'cta' => $cta, 'mensagem' => $message];
        }

        return $messages === [] ? [] : ['initial_messages' => $messages];
    }

    public function resolveLaunch(string $value, ?string $prefilledMessage = null): ContactChannelLaunchResolution
    {
        $digits = $this->targetDigits($value);
        if ($digits === null) {
            return new ContactChannelLaunchResolution(null);
        }

        $uri = 'https://wa.me/'.$digits;
        $message = trim((string) $prefilledMessage);
        if ($message !== '') {
            $uri .= '?text='.rawurlencode($message);
        }

        return new ContactChannelLaunchResolution($uri);
    }

    private function targetDigits(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_URL)) {
            $parsed = parse_url($trimmed);
            $host = strtolower(trim((string) ($parsed['host'] ?? '')));
            if ($host === 'wa.me') {
                $digits = preg_replace('/[^0-9]/', '', trim((string) ($parsed['path'] ?? ''), '/')) ?? '';

                return $this->isValidDigits($digits) ? $digits : null;
            }
            if ($host === 'api.whatsapp.com') {
                parse_str((string) ($parsed['query'] ?? ''), $query);
                $digits = preg_replace('/[^0-9]/', '', (string) ($query['phone'] ?? '')) ?? '';

                return $this->isValidDigits($digits) ? $digits : null;
            }

            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $trimmed) ?? '';

        return $this->isValidDigits($digits) ? $digits : null;
    }

    private function isValidDigits(string $digits): bool
    {
        $length = mb_strlen($digits);

        return $length >= 10 && $length <= 15;
    }
}
