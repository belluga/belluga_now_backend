<?php

declare(strict_types=1);

namespace Belluga\Events\Capabilities;

use Belluga\Events\Contracts\EventCapabilityHandlerContract;
use Illuminate\Validation\ValidationException;

class MultipleOccurrencesCapabilityHandler implements EventCapabilityHandlerContract
{
    public function key(): string
    {
        return EventCapabilityKey::MULTIPLE_OCCURRENCES;
    }

    public function mergeEventConfig(?array $incomingConfig, array $currentConfig): array
    {
        $enabled = (bool) ($currentConfig['enabled'] ?? false);

        if ($incomingConfig !== null && array_key_exists('enabled', $incomingConfig)) {
            $enabled = (bool) $incomingConfig['enabled'];
        }

        return [
            'enabled' => $enabled,
        ];
    }

    public function normalizeTenantConfig(?array $tenantConfig): array
    {
        $allowMultiple = (bool) (($tenantConfig['allow_multiple'] ?? false));
        $maxOccurrences = $tenantConfig['max_occurrences'] ?? null;

        if ($maxOccurrences === null || $maxOccurrences === '' || $maxOccurrences === 0 || $maxOccurrences === '0') {
            $maxOccurrences = null;
        } elseif (is_numeric($maxOccurrences)) {
            $maxOccurrences = (int) $maxOccurrences;
            if ($maxOccurrences < 1) {
                $maxOccurrences = null;
            }
        } else {
            $maxOccurrences = null;
        }

        return [
            'allow_multiple' => $allowMultiple,
            'max_occurrences' => $maxOccurrences,
        ];
    }

    public function assertScheduleConstraints(array $eventConfig, array $tenantConfig, array $occurrences): void
    {
        $occurrenceCount = count($occurrences);
        if ($occurrenceCount <= 1) {
            return;
        }

        $isTenantAvailable = (bool) ($tenantConfig['allow_multiple'] ?? false);
        $isEventEnabled = (bool) ($eventConfig['enabled'] ?? false);
        $isEffective = $isTenantAvailable && $isEventEnabled;

        if (! $isEffective) {
            throw ValidationException::withMessages([
                'occurrences' => ['Multiple occurrences are disabled for this tenant/event.'],
            ]);
        }

        $maxOccurrences = $tenantConfig['max_occurrences'] ?? null;
        if (is_int($maxOccurrences) && $maxOccurrences > 0 && $occurrenceCount > $maxOccurrences) {
            throw ValidationException::withMessages([
                'occurrences' => ["Maximum allowed occurrences is {$maxOccurrences}."],
            ]);
        }
    }
}

