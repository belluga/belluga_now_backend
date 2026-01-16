<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Http\Requests\TenantTelemetryStoreRequest;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Http\JsonResponse;

class TenantTelemetrySettingsController
{
    public function index(): JsonResponse
    {
        $settings = TenantPushSettings::current();
        $telemetry = $this->normalizeTelemetry($settings?->telemetry ?? []);

        return response()->json([
            'data' => $telemetry,
            'available_events' => $this->availableEvents(),
        ]);
    }

    public function store(TenantTelemetryStoreRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $settings = TenantPushSettings::current();
        $telemetry = $this->upsertTelemetry(
            $this->normalizeTelemetry($settings?->telemetry ?? []),
            $payload
        );

        if (! $settings) {
            $settings = TenantPushSettings::create(['telemetry' => $telemetry]);
        } else {
            $settings->fill(['telemetry' => $telemetry]);
            $settings->save();
        }

        return response()->json([
            'data' => $settings->telemetry ?? [],
            'available_events' => $this->availableEvents(),
        ]);
    }

    public function destroy(string $type): JsonResponse
    {
        $settings = TenantPushSettings::current();
        $telemetry = $this->removeTelemetry(
            $this->normalizeTelemetry($settings?->telemetry ?? []),
            $type
        );

        if (! $settings) {
            return response()->json([
                'data' => $telemetry,
                'available_events' => $this->availableEvents(),
            ]);
        }

        $settings->fill(['telemetry' => $telemetry]);
        $settings->save();

        return response()->json([
            'data' => $settings->telemetry ?? [],
            'available_events' => $this->availableEvents(),
        ]);
    }

    /**
     * @param array<mixed> $telemetry
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTelemetry(array $telemetry): array
    {
        $normalized = [];
        foreach ($telemetry as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $type = $entry['type'] ?? null;
            if (! is_string($type) || $type === '') {
                continue;
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $telemetry
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function upsertTelemetry(array $telemetry, array $payload): array
    {
        $indexed = [];
        foreach ($telemetry as $entry) {
            $type = $entry['type'] ?? null;
            if (! is_string($type) || $type === '') {
                continue;
            }
            $indexed[$type] = $entry;
        }

        $type = $payload['type'] ?? null;
        if (is_string($type) && $type !== '') {
            $indexed[$type] = $payload;
        }

        return array_values($indexed);
    }

    /**
     * @param array<int, array<string, mixed>> $telemetry
     * @param string $type
     * @return array<int, array<string, mixed>>
     */
    private function removeTelemetry(array $telemetry, string $type): array
    {
        $filtered = [];
        foreach ($telemetry as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $entryType = $entry['type'] ?? null;
            if ($entryType === $type) {
                continue;
            }
            $filtered[] = $entry;
        }

        return $filtered;
    }

    /**
     * @return array<int, string>
     */
    private function availableEvents(): array
    {
        $events = config('belluga_push_handler.telemetry.available_events', []);
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
}
