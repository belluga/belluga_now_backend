<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TenantPushSettings extends Model
{
    use UsesTenantConnection;

    protected $collection = 'tenant_push_settings';

    protected $hidden = [
        'firebase_credentials_id',
    ];

    protected $fillable = [
        'push_message_types',
        'push_message_routes',
        'max_ttl_days',
        'telemetry',
        'firebase',
        'push',
    ];

    protected $casts = [
        'push_message_types' => 'array',
        'push_message_routes' => 'array',
        'max_ttl_days' => 'integer',
        'firebase' => 'array',
        'push' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @param mixed $value
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
     * @param array<mixed> $telemetry
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

    public static function current(): ?self
    {
        return static::query()->first();
    }
}
