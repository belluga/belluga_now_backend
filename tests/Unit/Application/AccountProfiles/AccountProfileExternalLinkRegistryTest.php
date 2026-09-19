<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileExternalLinkRegistry;
use App\Application\AccountProfiles\AccountProfileRegistrySeeder;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Tests\TestCase;

class AccountProfileExternalLinkRegistryTest extends TestCase
{
    public function test_current_limit_comes_from_the_canonical_capability_resolver(): void
    {
        app(AccountProfileRegistrySeeder::class)->ensureDefaults();
        $profile = new AccountProfile(['profile_type' => 'artist']);

        $this->assertSame(3, app(AccountProfileExternalLinkRegistry::class)->currentLimit($profile));
    }

    public function test_current_limit_resolver_accepts_a_profile_scope(): void
    {
        app(AccountProfileRegistrySeeder::class)->ensureDefaults();
        $type = TenantProfileType::query()->where('type', 'artist')->firstOrFail();
        $capabilities = $type->capabilities;
        $capabilities['has_external_links']['parameters']['max_links'] = 2;
        $type->capabilities = $capabilities;
        $type->save();

        $this->assertSame(2, app(AccountProfileExternalLinkRegistry::class)->currentLimit(
            new AccountProfile(['profile_type' => 'artist']),
        ));
    }

    public function test_missing_profile_scope_fails_closed_without_a_numeric_fallback(): void
    {
        $this->assertSame(0, app(AccountProfileExternalLinkRegistry::class)->currentLimit());
    }

    public function test_stored_duplicate_types_and_identities_fail_closed_without_hiding_unique_items(): void
    {
        $resolved = app(AccountProfileExternalLinkRegistry::class)->normalizeStored([
            ['id' => 'duplicate-type-a', 'type' => 'instagram', 'url' => 'https://instagram.com/first'],
            ['id' => 'duplicate-type-b', 'type' => 'instagram', 'url' => 'https://instagram.com/second'],
            ['id' => 'website', 'type' => 'website', 'url' => 'https://example.org', 'label' => 'Official'],
        ], 3);

        $this->assertSame([
            ['id' => 'website', 'type' => 'website', 'url' => 'https://example.org', 'label' => 'Official'],
        ], $resolved);
    }

    public function test_stored_over_limit_payload_fails_closed_before_normalization(): void
    {
        $payload = [];
        for ($index = 0; $index < 4; $index++) {
            $payload[] = [
                'id' => "website-$index",
                'type' => 'website',
                'url' => "https://example-$index.org",
                'label' => "Site $index",
            ];
        }

        $resolved = app(AccountProfileExternalLinkRegistry::class)->normalizeStored($payload, 3);

        $this->assertSame([], $resolved);
    }
}
