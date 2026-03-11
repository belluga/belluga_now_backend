<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Models\Tenants;

use Belluga\Settings\Models\Tenants\TenantSettings as PackageTenantSettings;

class TenantPushSettings extends PackageTenantSettings
{
    protected $hidden = [
        'firebase_credentials_id',
    ];

    protected $fillable = [
        'telemetry',
        'firebase',
        'push',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     */
    public function getTelemetryAttribute($value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            $telemetry = $value->getArrayCopy();
        } elseif (is_array($value)) {
            $telemetry = $value;
        } elseif ($value instanceof \Traversable) {
            $telemetry = iterator_to_array($value);
        } elseif (is_object($value)) {
            $telemetry = (array) $value;
        } else {
            $telemetry = [];
        }

        return $this->normalizeTelemetry($telemetry);
    }

    /**
     * @param  array<mixed>  $telemetry
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTelemetry(array $telemetry): array
    {
        if (isset($telemetry['mixpanel_token']) || isset($telemetry['enabled_events'])) {
            return [[
                'type' => 'mixpanel',
                'token' => (string) ($telemetry['mixpanel_token'] ?? ''),
                'events' => is_array($telemetry['enabled_events'] ?? null)
                    ? $telemetry['enabled_events']
                    : [],
            ]];
        }

        return $telemetry;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPushConfig(): array
    {
        $push = $this->getAttribute('push');

        if ($push instanceof \MongoDB\Model\BSONDocument || $push instanceof \MongoDB\Model\BSONArray) {
            return $push->getArrayCopy();
        }
        if (is_array($push)) {
            return $push;
        }
        if ($push instanceof \Traversable) {
            return iterator_to_array($push);
        }
        if (is_object($push)) {
            return (array) $push;
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPushMessageRoutes(): array
    {
        $push = $this->getPushConfig();
        $routes = $push['message_routes'] ?? [];

        return is_array($routes) ? $routes : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPushMessageTypes(): array
    {
        $push = $this->getPushConfig();
        $types = $push['message_types'] ?? [];

        return is_array($types) ? $types : [];
    }

    public function getPushMaxTtlDays(): ?int
    {
        $push = $this->getPushConfig();
        $value = $push['max_ttl_days'] ?? null;

        return is_int($value) ? $value : null;
    }

    public static function current(): ?self
    {
        /** @var self|null $current */
        $current = parent::current();

        return $current;
    }
}
