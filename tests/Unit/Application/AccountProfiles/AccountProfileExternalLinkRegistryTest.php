<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileExternalLinkRegistry;
use App\Models\Tenants\AccountProfile;
use Tests\TestCase;

class AccountProfileExternalLinkRegistryTest extends TestCase
{
    public function test_current_limit_comes_from_the_temporary_capacity_source(): void
    {
        config(['external_links.max_per_profile' => 5]);

        $this->assertSame(5, (new AccountProfileExternalLinkRegistry)->currentLimit());
    }

    public function test_current_limit_resolver_accepts_a_profile_scope(): void
    {
        config(['external_links.max_per_profile' => 4]);

        $this->assertSame(4, (new AccountProfileExternalLinkRegistry)->currentLimit(new AccountProfile));
    }

    public function test_missing_temporary_capacity_source_fails_closed_without_a_numeric_fallback(): void
    {
        config(['external_links.max_per_profile' => null]);

        $this->assertSame(0, (new AccountProfileExternalLinkRegistry)->currentLimit());
    }

    public function test_stored_duplicate_types_and_identities_fail_closed_without_hiding_unique_items(): void
    {
        $resolved = (new AccountProfileExternalLinkRegistry)->normalizeStored([
            ['id' => 'duplicate-type-a', 'type' => 'instagram', 'url' => 'https://instagram.com/first'],
            ['id' => 'duplicate-type-b', 'type' => 'instagram', 'url' => 'https://instagram.com/second'],
            ['id' => 'website', 'type' => 'website', 'url' => 'https://example.org', 'label' => 'Official'],
        ]);

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

        $resolved = (new AccountProfileExternalLinkRegistry)->normalizeStored($payload);

        $this->assertSame([], $resolved);
    }
}
