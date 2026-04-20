<?php

declare(strict_types=1);

namespace App\Application\ProximityPreferences;

use App\Models\Tenants\AccountUser;
use App\Models\Tenants\ProximityPreference;

class ProximityPreferenceService
{
    public function findForUser(AccountUser $user): ?ProximityPreference
    {
        return ProximityPreference::query()
            ->where('owner_user_id', (string) $user->getAuthIdentifier())
            ->first();
    }

    /**
     * @param  array{
     *     max_distance_meters:int,
     *     location_preference:array{
     *         mode:string,
     *         fixed_reference:?array{
     *             source_kind:string,
     *             coordinate:array{lat:float|int|string,lng:float|int|string},
     *             label?:?string,
     *             entity_namespace?:?string,
     *             entity_type?:?string,
     *             entity_id?:?string
     *         }
     *     }
     * }  $payload
     */
    public function upsertForUser(AccountUser $user, array $payload): ProximityPreference
    {
        $normalized = $this->normalizePayload($payload);

        return ProximityPreference::query()->updateOrCreate(
            [
                'owner_user_id' => (string) $user->getAuthIdentifier(),
            ],
            $normalized,
        );
    }

    /**
     * @return array{
     *     max_distance_meters:int,
     *     location_preference:array{
     *         mode:string,
     *         fixed_reference:?array{
     *             source_kind:string,
     *             coordinate:array{lat:float,lng:float},
     *             label:?string,
     *             entity_namespace:?string,
     *             entity_type:?string,
     *             entity_id:?string
     *         }
     *     }
     * }
     */
    public function toPayload(ProximityPreference $preference): array
    {
        $fixedReference = data_get($preference->location_preference, 'fixed_reference');

        return [
            'max_distance_meters' => (int) $preference->max_distance_meters,
            'location_preference' => [
                'mode' => (string) data_get($preference->location_preference, 'mode', 'live_device_location'),
                'fixed_reference' => is_array($fixedReference)
                    ? [
                        'source_kind' => (string) ($fixedReference['source_kind'] ?? 'manual_coordinate'),
                        'coordinate' => [
                            'lat' => (float) data_get($fixedReference, 'coordinate.lat'),
                            'lng' => (float) data_get($fixedReference, 'coordinate.lng'),
                        ],
                        'label' => $this->nullableString($fixedReference['label'] ?? null),
                        'entity_namespace' => $this->nullableString($fixedReference['entity_namespace'] ?? null),
                        'entity_type' => $this->nullableString($fixedReference['entity_type'] ?? null),
                        'entity_id' => $this->nullableString($fixedReference['entity_id'] ?? null),
                    ]
                    : null,
            ],
        ];
    }

    /**
     * @param  array{
     *     max_distance_meters:int,
     *     location_preference:array{
     *         mode:string,
     *         fixed_reference:?array{
     *             source_kind:string,
     *             coordinate:array{lat:float|int|string,lng:float|int|string},
     *             label?:?string,
     *             entity_namespace?:?string,
     *             entity_type?:?string,
     *             entity_id?:?string
     *         }
     *     }
     * }  $payload
     * @return array{
     *     max_distance_meters:int,
     *     location_preference:array{
     *         mode:string,
     *         fixed_reference:?array{
     *             source_kind:string,
     *             coordinate:array{lat:float,lng:float},
     *             label:?string,
     *             entity_namespace:?string,
     *             entity_type:?string,
     *             entity_id:?string
     *         }
     *     }
     * }
     */
    public function normalizePayload(array $payload): array
    {
        $mode = (string) data_get($payload, 'location_preference.mode', 'live_device_location');
        $fixedReference = $mode === 'fixed_reference'
            ? $this->normalizeFixedReference(data_get($payload, 'location_preference.fixed_reference'))
            : null;

        return [
            'max_distance_meters' => (int) ($payload['max_distance_meters'] ?? 0),
            'location_preference' => [
                'mode' => $mode,
                'fixed_reference' => $fixedReference,
            ],
        ];
    }

    /**
     * @param  mixed  $fixedReference
     * @return array{
     *     source_kind:string,
     *     coordinate:array{lat:float,lng:float},
     *     label:?string,
     *     entity_namespace:?string,
     *     entity_type:?string,
     *     entity_id:?string
     * }
     */
    private function normalizeFixedReference(mixed $fixedReference): array
    {
        $fixedReference = is_array($fixedReference) ? $fixedReference : [];

        return [
            'source_kind' => (string) ($fixedReference['source_kind'] ?? 'manual_coordinate'),
            'coordinate' => [
                'lat' => (float) data_get($fixedReference, 'coordinate.lat'),
                'lng' => (float) data_get($fixedReference, 'coordinate.lng'),
            ],
            'label' => $this->nullableString($fixedReference['label'] ?? null),
            'entity_namespace' => $this->nullableString($fixedReference['entity_namespace'] ?? null),
            'entity_type' => $this->nullableString($fixedReference['entity_type'] ?? null),
            'entity_id' => $this->nullableString($fixedReference['entity_id'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
