<?php

declare(strict_types=1);

namespace App\Application\Telemetry;

use Belluga\Settings\Application\SettingsKernelService;
use Belluga\Settings\Contracts\SettingsStoreContract;

class TelemetrySettingsKernelBridge
{
    public function __construct(
        private readonly SettingsStoreContract $settingsStore,
        private readonly SettingsKernelService $settingsKernelService
    ) {
    }

    /**
     * @return array{location_freshness_minutes:int, trackers:array<int, array<string, mixed>>}
     */
    public function currentTelemetryConfig(): array
    {
        $raw = $this->settingsStore->getNamespaceValue('tenant', 'telemetry');

        return $this->normalizeTelemetryConfig($raw);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{location_freshness_minutes:int, trackers:array<int, array<string, mixed>>}
     */
    public function patchTelemetryConfig(mixed $user, array $payload): array
    {
        $updated = $this->settingsKernelService->patchNamespace('tenant', $user, 'telemetry', $payload);

        return $this->normalizeTelemetryConfig($updated);
    }

    /**
     * @param array<string, mixed> $tracker
     * @return array{location_freshness_minutes:int, trackers:array<int, array<string, mixed>>}
     */
    public function upsertTracker(mixed $user, array $tracker): array
    {
        $config = $this->currentTelemetryConfig();
        $indexed = [];

        foreach ($config['trackers'] as $entry) {
            $type = $entry['type'] ?? null;
            if (! is_string($type) || $type === '') {
                continue;
            }
            $indexed[$type] = $entry;
        }

        $normalized = $this->normalizeTracker($tracker);
        $type = $normalized['type'] ?? null;
        if (is_string($type) && $type !== '') {
            $indexed[$type] = $normalized;
        }

        return $this->patchTelemetryConfig($user, [
            'trackers' => array_values($indexed),
        ]);
    }

    /**
     * @return array{location_freshness_minutes:int, trackers:array<int, array<string, mixed>>}
     */
    public function removeTracker(mixed $user, string $type): array
    {
        $config = $this->currentTelemetryConfig();
        $filtered = [];
        foreach ($config['trackers'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (($entry['type'] ?? null) === $type) {
                continue;
            }
            $filtered[] = $entry;
        }

        return $this->patchTelemetryConfig($user, [
            'trackers' => array_values($filtered),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function availableEvents(): array
    {
        $events = config('telemetry.available_events', []);
        if (! is_array($events)) {
            return [];
        }

        $normalized = [];
        foreach ($events as $event) {
            if (! is_string($event) || $event === '') {
                continue;
            }
            $normalized[] = $event;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{location_freshness_minutes:int, trackers:array<int, array<string, mixed>>}
     */
    private function normalizeTelemetryConfig(array $raw): array
    {
        $defaultMinutes = $this->defaultLocationFreshnessMinutes();

        if ($raw === []) {
            return [
                'location_freshness_minutes' => $defaultMinutes,
                'trackers' => [],
            ];
        }

        $minutes = $defaultMinutes;
        $trackersRaw = [];

        if (array_is_list($raw)) {
            $trackersRaw = $raw;
        } else {
            $minutesRaw = $raw['location_freshness_minutes'] ?? null;
            if (is_int($minutesRaw) && $minutesRaw > 0) {
                $minutes = $minutesRaw;
            }

            $trackersRaw = $raw['trackers'] ?? [];
            if (! is_array($trackersRaw)) {
                $trackersRaw = [];
            }
        }

        return [
            'location_freshness_minutes' => $minutes,
            'trackers' => $this->normalizeTrackers($trackersRaw),
        ];
    }

    /**
     * @param array<int, mixed> $trackers
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTrackers(array $trackers): array
    {
        $normalized = [];
        foreach ($trackers as $tracker) {
            if (! is_array($tracker)) {
                continue;
            }

            $entry = $this->normalizeTracker($tracker);
            $type = $entry['type'] ?? null;
            if (! is_string($type) || $type === '') {
                continue;
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $tracker
     * @return array<string, mixed>
     */
    private function normalizeTracker(array $tracker): array
    {
        $entry = $tracker;
        $entry['track_all'] = filter_var($entry['track_all'] ?? false, FILTER_VALIDATE_BOOL);

        $events = $entry['events'] ?? [];
        if (! is_array($events)) {
            $events = [];
        }
        $entry['events'] = array_values(array_unique(array_filter($events, static fn (mixed $event): bool => is_string($event) && $event !== '')));

        return $entry;
    }

    private function defaultLocationFreshnessMinutes(): int
    {
        $value = (int) config('telemetry.location_freshness_minutes', 5);
        return $value > 0 ? $value : 5;
    }
}
