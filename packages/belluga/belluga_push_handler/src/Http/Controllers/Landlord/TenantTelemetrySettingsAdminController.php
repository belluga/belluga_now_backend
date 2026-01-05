<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Landlord;

use App\Models\Landlord\Tenant;
use Belluga\PushHandler\Http\Requests\TenantTelemetryStoreRequest;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Http\JsonResponse;

class TenantTelemetrySettingsAdminController
{
    public function index(string $tenant_slug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenant_slug)->firstOrFail();
        $tenant->makeCurrent();

        $settings = TenantPushSettings::current();
        $telemetry = $this->normalizeTelemetry($settings?->telemetry ?? []);

        $tenant->forgetCurrent();

        return response()->json(['data' => $telemetry]);
    }

    public function store(TenantTelemetryStoreRequest $request, string $tenant_slug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenant_slug)->firstOrFail();
        $tenant->makeCurrent();

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

        $tenant->forgetCurrent();

        return response()->json(['data' => $settings->telemetry ?? []]);
    }

    public function destroy(string $tenant_slug, string $type): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenant_slug)->firstOrFail();
        $tenant->makeCurrent();

        $settings = TenantPushSettings::current();
        $telemetry = $this->removeTelemetry(
            $this->normalizeTelemetry($settings?->telemetry ?? []),
            $type
        );

        if (! $settings) {
            $tenant->forgetCurrent();

            return response()->json(['data' => $telemetry]);
        }

        $settings->fill(['telemetry' => $telemetry]);
        $settings->save();

        $tenant->forgetCurrent();

        return response()->json(['data' => $settings->telemetry ?? []]);
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
}
