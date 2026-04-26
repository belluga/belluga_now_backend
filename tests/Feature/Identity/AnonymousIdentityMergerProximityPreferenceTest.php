<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\AnonymousIdentityMerger;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\ProximityPreference;
use Carbon\Carbon;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AnonymousIdentityMergerProximityPreferenceTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        ProximityPreference::query()->delete();
        AccountUser::query()->delete();
    }

    public function test_merge_moves_latest_preference_payload_to_target_and_deletes_sources(): void
    {
        $target = $this->createUser('registered');
        $firstSource = $this->createUser('anonymous');
        $secondSource = $this->createUser('anonymous');

        $this->createPreference(
            ownerUserId: (string) $target->_id,
            maxDistanceMeters: 10000,
            lat: -20.0,
            lng: -40.0,
            updatedAt: Carbon::parse('2026-04-20T10:00:00Z'),
            label: 'Target old',
        );
        $this->createPreference(
            ownerUserId: (string) $firstSource->_id,
            maxDistanceMeters: 20000,
            lat: -21.0,
            lng: -41.0,
            updatedAt: Carbon::parse('2026-04-20T11:00:00Z'),
            label: 'Source latest',
        );
        $this->createPreference(
            ownerUserId: (string) $secondSource->_id,
            maxDistanceMeters: 15000,
            lat: -22.0,
            lng: -42.0,
            updatedAt: Carbon::parse('2026-04-20T09:00:00Z'),
            label: 'Source oldest',
        );

        app(AnonymousIdentityMerger::class)->merge($target, [$firstSource, $secondSource]);

        $this->assertSame(
            1,
            ProximityPreference::query()->count(),
        );

        $preference = ProximityPreference::query()
            ->where('owner_user_id', (string) $target->_id)
            ->firstOrFail();

        $this->assertSame(20000, (int) $preference->max_distance_meters);
        $this->assertSame(
            -21.0,
            (float) data_get($preference->location_preference, 'fixed_reference.coordinate.lat'),
        );
        $this->assertSame(
            'Source latest',
            data_get($preference->location_preference, 'fixed_reference.label'),
        );
        $this->assertFalse(
            ProximityPreference::query()->where('owner_user_id', (string) $firstSource->_id)->exists(),
        );
        $this->assertFalse(
            ProximityPreference::query()->where('owner_user_id', (string) $secondSource->_id)->exists(),
        );
    }

    public function test_repeated_merge_is_idempotent_for_proximity_preferences(): void
    {
        $target = $this->createUser('registered');
        $source = $this->createUser('anonymous');

        $this->createPreference(
            ownerUserId: (string) $source->_id,
            maxDistanceMeters: 33000,
            lat: -19.5,
            lng: -39.5,
            updatedAt: Carbon::parse('2026-04-20T12:00:00Z'),
            label: 'Latest once',
        );

        $merger = app(AnonymousIdentityMerger::class);
        $merger->merge($target, [$source]);
        $merger->merge($target, [$source]);

        $this->assertSame(1, ProximityPreference::query()->count());

        $preference = ProximityPreference::query()
            ->where('owner_user_id', (string) $target->_id)
            ->firstOrFail();

        $this->assertSame(33000, (int) $preference->max_distance_meters);
        $this->assertSame(
            'Latest once',
            data_get($preference->location_preference, 'fixed_reference.label'),
        );
    }

    private function createUser(string $identityState): AccountUser
    {
        return AccountUser::create([
            'identity_state' => $identityState,
            'fingerprints' => [
                [
                    'hash' => uniqid('pref-merge-hash-', true),
                ],
            ],
            'emails' => $identityState === 'registered'
                ? [uniqid('registered-', true).'@example.org']
                : [],
            'password' => $identityState === 'registered' ? 'Secret!234' : null,
        ]);
    }

    private function createPreference(
        string $ownerUserId,
        int $maxDistanceMeters,
        float $lat,
        float $lng,
        Carbon $updatedAt,
        string $label,
    ): ProximityPreference {
        $preference = ProximityPreference::query()->create([
            'owner_user_id' => $ownerUserId,
            'max_distance_meters' => $maxDistanceMeters,
            'location_preference' => [
                'mode' => 'fixed_reference',
                'fixed_reference' => [
                    'source_kind' => 'manual_coordinate',
                    'coordinate' => [
                        'lat' => $lat,
                        'lng' => $lng,
                    ],
                    'label' => $label,
                    'entity_namespace' => null,
                    'entity_type' => null,
                    'entity_id' => null,
                ],
            ],
        ]);

        $preference->updated_at = $updatedAt;
        $preference->created_at = $updatedAt->copy()->subMinute();
        $preference->save();

        return $preference->fresh();
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(\App\Application\Initialization\SystemInitializationService::class);
        $service->initialize(new \App\Application\Initialization\InitializationPayload(
            landlord: ['name' => 'Belluga Solutions'],
            tenant: [
                'name' => 'Belluga Primary',
                'subdomain' => $this->tenant->subdomain,
            ],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: [
                'name' => 'Super Admin',
                'email' => 'admin@belluga.test',
                'password' => 'Secret!234',
            ],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#ffffff',
                'secondary_seed_color' => '#000000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ["{$this->tenant->subdomain}.{$this->host}"],
        ));
    }
}
