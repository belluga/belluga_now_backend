<?php

declare(strict_types=1);

namespace App\Application\ProximityPreferences;

use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\ProximityPreference;
use App\Models\Tenants\TenantProfileType;

class ProximityPreferenceService
{
    private const REFERENCE_STATUS_ACTIVE = 'active';

    private const REFERENCE_STATUS_DISABLED = 'disabled';

    private const REFERENCE_REASON_ELIGIBLE = 'eligible';

    private const REFERENCE_REASON_MANUAL_COORDINATE = 'manual_coordinate';

    private const REFERENCE_REASON_SOURCE_CAPABILITY_DISABLED = 'source_capability_disabled';

    public function __construct(
        private readonly AccountProfileTypeCapabilityCatalog $capabilityCatalog,
    ) {}

    public function findForUser(AccountUser $user): ?ProximityPreference
    {
        return ProximityPreference::query()
            ->where('owner_user_id', (string) $user->getAuthIdentifier())
            ->first();
    }

    /**
     * @param  array{
     *     max_distance_meters:int,
     *     use_reference_point_for_routes?:?bool,
     *     location_preference:array{
     *         mode:string,
     *         fixed_reference:?array{
     *             source_kind:string,
     *             coordinate:array{lat:float|int|string,lng:float|int|string},
     *             label?:?string,
     *             entity_namespace?:?string,
     *             entity_type?:?string,
     *             entity_id?:?string,
     *             entity_slug?:?string
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
     *     use_reference_point_for_routes:?bool,
     *     location_preference:array{
     *         mode:string,
     *         fixed_reference:?array{
     *             source_kind:string,
     *             coordinate:array{lat:float,lng:float},
     *             label:?string,
     *             entity_namespace:?string,
     *             entity_type:?string,
     *             entity_id:?string,
     *             entity_slug:?string,
     *             reference_status:string,
     *             reference_status_reason:string,
     *             blocked_capability_key:?string
     *         }
     *     }
     * }
     */
    public function toPayload(ProximityPreference $preference): array
    {
        $fixedReference = data_get($preference->location_preference, 'fixed_reference');

        return [
            'max_distance_meters' => (int) $preference->max_distance_meters,
            'use_reference_point_for_routes' => $this->nullableBool(
                $preference->use_reference_point_for_routes ?? null,
            ),
            'location_preference' => [
                'mode' => (string) data_get($preference->location_preference, 'mode', 'live_device_location'),
                'fixed_reference' => is_array($fixedReference)
                    ? $this->resolveFixedReferencePayload($fixedReference)
                    : null,
            ],
        ];
    }

    /**
     * @param  array{
     *     max_distance_meters:int,
     *     use_reference_point_for_routes?:?bool,
     *     location_preference:array{
     *         mode:string,
     *         fixed_reference:?array{
     *             source_kind:string,
     *             coordinate:array{lat:float|int|string,lng:float|int|string},
     *             label?:?string,
     *             entity_namespace?:?string,
     *             entity_type?:?string,
     *             entity_id?:?string,
     *             entity_slug?:?string
     *         }
     *     }
     * }  $payload
     * @return array{
     *     max_distance_meters:int,
     *     use_reference_point_for_routes:?bool,
     *     location_preference:array{
     *         mode:string,
     *         fixed_reference:?array{
     *             source_kind:string,
     *             coordinate:array{lat:float,lng:float},
     *             label:?string,
     *             entity_namespace:?string,
     *             entity_type:?string,
     *             entity_id:?string,
     *             entity_slug:?string
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
            'use_reference_point_for_routes' => $this->nullableBool(
                $payload['use_reference_point_for_routes'] ?? null,
            ),
            'location_preference' => [
                'mode' => $mode,
                'fixed_reference' => $fixedReference,
            ],
        ];
    }

    /**
     * @return array{
     *     source_kind:string,
     *     coordinate:array{lat:float,lng:float},
     *     label:?string,
     *     entity_namespace:?string,
     *     entity_type:?string,
     *     entity_id:?string,
     *     entity_slug:?string
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
            'entity_slug' => $this->nullableString($fixedReference['entity_slug'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $fixedReference
     * @return array{
     *     source_kind:string,
     *     coordinate:array{lat:float,lng:float},
     *     label:?string,
     *     entity_namespace:?string,
     *     entity_type:?string,
     *     entity_id:?string,
     *     entity_slug:?string,
     *     reference_status:string,
     *     reference_status_reason:string,
     *     blocked_capability_key:?string
     * }
     */
    private function resolveFixedReferencePayload(array $fixedReference): array
    {
        $payload = [
            'source_kind' => (string) ($fixedReference['source_kind'] ?? 'manual_coordinate'),
            'coordinate' => [
                'lat' => (float) data_get($fixedReference, 'coordinate.lat'),
                'lng' => (float) data_get($fixedReference, 'coordinate.lng'),
            ],
            'label' => $this->nullableString($fixedReference['label'] ?? null),
            'entity_namespace' => $this->nullableString($fixedReference['entity_namespace'] ?? null),
            'entity_type' => $this->nullableString($fixedReference['entity_type'] ?? null),
            'entity_id' => $this->nullableString($fixedReference['entity_id'] ?? null),
            'entity_slug' => $this->nullableString($fixedReference['entity_slug'] ?? null),
        ];

        $resolution = $this->resolveReferenceStatus($payload);

        return [
            ...$payload,
            'reference_status' => $resolution['status'],
            'reference_status_reason' => $resolution['reason'],
            'blocked_capability_key' => $resolution['blocked_capability_key'],
        ];
    }

    /**
     * @param  array<string, mixed>  $fixedReference
     * @return array{status:string,reason:string,blocked_capability_key:?string}
     */
    private function resolveReferenceStatus(array $fixedReference): array
    {
        if (($fixedReference['source_kind'] ?? null) !== 'entity_reference') {
            return $this->activeReference(self::REFERENCE_REASON_MANUAL_COORDINATE);
        }

        if (($fixedReference['entity_namespace'] ?? null) === 'account_profile') {
            return $this->resolveAccountProfileReferenceStatus($fixedReference);
        }

        return $this->activeReference(self::REFERENCE_REASON_ELIGIBLE);
    }

    /**
     * @param  array<string, mixed>  $fixedReference
     * @return array{status:string,reason:string,blocked_capability_key:?string}
     */
    private function resolveAccountProfileReferenceStatus(array $fixedReference): array
    {
        $profileType = $this->nullableString($fixedReference['entity_type'] ?? null);
        $entityId = $this->nullableString($fixedReference['entity_id'] ?? null);

        if ($entityId !== null) {
            $profile = AccountProfile::query()->find($entityId);
            if ($profile instanceof AccountProfile) {
                $currentProfileType = $this->nullableString($profile->profile_type ?? null);
                if ($currentProfileType !== null) {
                    $profileType = $currentProfileType;
                }
            }
        }

        $capabilities = [];
        if ($profileType !== null) {
            $type = TenantProfileType::query()
                ->where('type', $profileType)
                ->first();
            $capabilities = is_array($type?->capabilities ?? null)
                ? $type->capabilities
                : [];
        }

        if (! $this->capabilityCatalog->isExplicitlyEnabled(
            AccountProfileTypeCapabilityCatalog::IS_REFERENCE_LOCATION_ENABLED,
            $capabilities,
        )) {
            return $this->disabledReference(
                $this->capabilityCatalog->firstDisabledRequirement(
                    AccountProfileTypeCapabilityCatalog::IS_REFERENCE_LOCATION_ENABLED,
                    $capabilities,
                ) ?? AccountProfileTypeCapabilityCatalog::IS_REFERENCE_LOCATION_ENABLED,
            );
        }

        return $this->activeReference(self::REFERENCE_REASON_ELIGIBLE);
    }

    /**
     * @return array{status:string,reason:string,blocked_capability_key:null}
     */
    private function activeReference(string $reason): array
    {
        return [
            'status' => self::REFERENCE_STATUS_ACTIVE,
            'reason' => $reason,
            'blocked_capability_key' => null,
        ];
    }

    /**
     * @return array{status:string,reason:string,blocked_capability_key:string}
     */
    private function disabledReference(string $blockedCapabilityKey): array
    {
        return [
            'status' => self::REFERENCE_STATUS_DISABLED,
            'reason' => self::REFERENCE_REASON_SOURCE_CAPABILITY_DISABLED,
            'blocked_capability_key' => $blockedCapabilityKey,
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

    private function nullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return null;
    }
}
