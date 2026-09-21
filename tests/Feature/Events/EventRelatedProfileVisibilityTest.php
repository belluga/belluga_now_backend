<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Integration\Events\AccountProfileResolverAdapter;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\SeedsTenantAccounts;

final class EventRelatedProfileVisibilityTest extends TestCaseTenant
{
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get => $this->landlord->tenant_primary;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();
        AccountProfile::query()->withTrashed()->forceDelete();
        TenantProfileType::query()->delete();
    }

    public function test_public_event_party_media_hides_dormant_capability_without_changing_management_selection(): void
    {
        [$account] = $this->seedAccountWithRole(['account-users:view']);
        $type = TenantProfileType::query()->create([
            'type' => 'event-related-media',
            'label' => 'Event Related Media',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => ['value' => true, 'parameters' => []],
                'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
                'is_publicly_navigable' => ['value' => true, 'parameters' => []],
                'has_avatar' => ['value' => true, 'parameters' => []],
                'has_cover' => ['value' => true, 'parameters' => []],
            ],
        ]);
        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->getKey(),
            'profile_type' => 'event-related-media',
            'display_name' => 'Event Related Media',
            'slug' => 'event-related-media',
            'visibility' => 'public',
            'is_active' => true,
            'avatar_url' => 'https://cdn.example.test/event-avatar.jpg',
            'cover_url' => 'https://cdn.example.test/event-cover.jpg',
        ]);

        $resolver = app(AccountProfileResolverAdapter::class);
        foreach ([['avatar', 'cover'], ['cover', 'avatar']] as [$kind, $otherKind]) {
            $initial = $resolver->resolveExistingPublicEventPartyProfilesByIds([(string) $profile->getKey()]);
            $initialRow = $initial[(string) $profile->getKey()];
            $this->assertIsString($initialRow["{$kind}_url"] ?? null);
            $this->assertIsString($initialRow["{$otherKind}_url"] ?? null);

            $capabilities = $type->capabilities;
            $capabilities["has_{$kind}"]['value'] = false;
            $type->capabilities = $capabilities;
            $type->save();

            $disabled = $resolver->resolveExistingPublicEventPartyProfilesByIds([(string) $profile->getKey()]);
            $disabledRow = $disabled[(string) $profile->getKey()];
            $this->assertNull($disabledRow["{$kind}_url"] ?? null);
            $this->assertSame($initialRow["{$otherKind}_url"], $disabledRow["{$otherKind}_url"] ?? null);
            $management = $resolver->resolveExistingEventPartyProfilesByIds([(string) $profile->getKey()]);
            $this->assertSame($initialRow["{$kind}_url"], $management[(string) $profile->getKey()]["{$kind}_url"] ?? null);

            $capabilities["has_{$kind}"]['value'] = true;
            $type->capabilities = $capabilities;
            $type->save();

            $restored = $resolver->resolveExistingPublicEventPartyProfilesByIds([(string) $profile->getKey()]);
            $this->assertSame($initialRow["{$kind}_url"], $restored[(string) $profile->getKey()]["{$kind}_url"] ?? null);
        }
    }
}
