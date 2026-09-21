<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\RestoresTenantContextAfterRequest;
use Tests\Traits\SeedsTenantAccounts;

final class AccountProfileMediaAuthorizationTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use RestoresTenantContextAfterRequest;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get => $this->landlord->tenant_primary;
    }

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::query()->firstOrFail()->makeCurrent();
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);
        AccountProfile::query()->delete();
        TenantProfileType::query()->delete();
        Storage::fake('public');
        [$this->account] = $this->seedAccountWithRole(['account-users:view']);
    }

    public function test_public_avatar_and_cover_are_available_for_a_navigable_only_profile_when_the_matching_capability_is_enabled(): void
    {
        $profile = $this->profileWithStoredMedia('navigable-only', published: true);

        foreach (['avatar', 'cover'] as $kind) {
            $response = $this->get("{$this->base_api_tenant}media/account-profiles/{$profile->getKey()}/{$kind}")
                ->assertOk()
                ->assertHeader('ETag');
            $this->assertBinaryFileResponseBytes($response, "{$kind}-bytes");
        }
    }

    public function test_public_media_eligibility_precedes_conditional_validators_for_canonical_and_web_alias_routes(): void
    {
        $profile = $this->profileWithStoredMedia('conditional-public-media', published: true);

        foreach ([['avatar', 'has_avatar'], ['cover', 'has_cover']] as [$kind, $capability]) {
            $canonicalUrl = "{$this->base_api_tenant}media/account-profiles/{$profile->getKey()}/{$kind}";
            $webAliasUrl = "{$this->base_tenant_url}account-profiles/{$profile->getKey()}/{$kind}";
            $eligible = $this->get($canonicalUrl)->assertOk();
            $etag = trim((string) $eligible->headers->get('ETag'));
            $lastModified = trim((string) $eligible->headers->get('Last-Modified'));

            $this->assertNotSame('', $etag);
            $this->assertNotSame('', $lastModified);
            $this->assertBinaryFileResponseBytes($eligible, "{$kind}-bytes");
            $webAliasEligible = $this->get($webAliasUrl)->assertOk();
            $this->assertBinaryFileResponseBytes($webAliasEligible, "{$kind}-bytes");

            $type = TenantProfileType::query()->where('type', $profile->profile_type)->firstOrFail();
            $capabilities = $type->capabilities;
            $capabilities[$capability]['value'] = false;
            $type->capabilities = $capabilities;
            $type->save();

            foreach ([
                'If-None-Match' => $etag,
                'If-Modified-Since' => $lastModified,
            ] as $header => $value) {
                foreach ([$canonicalUrl, $webAliasUrl] as $url) {
                    $denied = $this->get($url, [$header => $value]);

                    $denied->assertNotFound();
                    $this->assertNotInstanceOf(BinaryFileResponse::class, $denied->baseResponse);
                    $this->assertStringNotContainsString("{$kind}-bytes", (string) $denied->getContent());
                }
            }
        }
    }

    public function test_same_tenant_viewer_reads_protected_avatar_bytes_for_a_soft_deleted_profile_without_public_eligibility(): void
    {
        $profile = $this->profileWithStoredMedia('protected-soft-deleted', published: false);
        $profile->delete();

        $response = $this->get("{$this->base_tenant_api_admin}account_profiles/{$profile->getKey()}/media/avatar", $this->getHeaders())
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertHeader('Vary');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $vary = array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', (string) $response->headers->get('Vary')),
        );
        $this->assertContains('authorization', $vary);
        $this->assertContains('host', $vary);
        $this->assertBinaryFileResponseBytes($response, 'avatar-bytes');
    }

    public function test_media_capability_and_asset_denials_are_pairwise_for_public_and_protected_routes(): void
    {
        foreach ([['avatar', 'has_avatar'], ['cover', 'has_cover']] as [$kind, $capability]) {
            $disabled = $this->profileWithStoredMedia("{$kind}-disabled", published: true);
            $type = TenantProfileType::query()->where('type', $disabled->profile_type)->firstOrFail();
            $capabilities = $type->capabilities;
            $capabilities[$capability]['value'] = false;
            $type->capabilities = $capabilities;
            $type->save();

            $public = $this->get("{$this->base_api_tenant}media/account-profiles/{$disabled->getKey()}/{$kind}");
            $public->assertNotFound();
            $this->assertNotInstanceOf(BinaryFileResponse::class, $public->baseResponse);
            $protected = $this->get("{$this->base_tenant_api_admin}account_profiles/{$disabled->getKey()}/media/{$kind}", $this->getHeaders());
            $protected->assertNotFound();
            $this->assertNotInstanceOf(BinaryFileResponse::class, $protected->baseResponse);

            $missing = $this->profileWithStoredMedia("{$kind}-missing", published: true, storeMedia: false);
            $publicMissing = $this->get("{$this->base_api_tenant}media/account-profiles/{$missing->getKey()}/{$kind}");
            $publicMissing->assertNotFound();
            $this->assertNotInstanceOf(BinaryFileResponse::class, $publicMissing->baseResponse);
            $protectedMissing = $this->get("{$this->base_tenant_api_admin}account_profiles/{$missing->getKey()}/media/{$kind}", $this->getHeaders());
            $protectedMissing->assertNotFound();
            $this->assertNotInstanceOf(BinaryFileResponse::class, $protectedMissing->baseResponse);
        }
    }

    public function test_disabled_media_capability_hides_public_and_admin_presentation_without_deleting_or_changing_the_other_kind(): void
    {
        $profile = $this->profileWithStoredMedia('dormant-presentation', published: true);
        $detailUrl = "{$this->base_api_tenant}account_profiles/{$profile->slug}";
        $adminUrl = "{$this->base_tenant_api_admin}account_profiles/{$profile->getKey()}";

        foreach ([['avatar', 'cover'], ['cover', 'avatar']] as [$kind, $otherKind]) {
            $initialDetail = $this->getJson($detailUrl)->assertOk();
            $initialAdmin = $this->getJson($adminUrl, $this->getHeaders())->assertOk();
            $initialPublicUrl = $initialDetail->json("data.{$kind}_url");
            $initialOtherPublicUrl = $initialDetail->json("data.{$otherKind}_url");
            $initialAdminUrl = $initialAdmin->json("data.admin_{$kind}_url");

            $this->assertIsString($initialPublicUrl);
            $this->assertIsString($initialOtherPublicUrl);
            $this->assertIsString($initialAdminUrl);

            $type = TenantProfileType::query()->where('type', $profile->profile_type)->firstOrFail();
            $capabilities = $type->capabilities;
            $capabilities["has_{$kind}"]['value'] = false;
            $type->capabilities = $capabilities;
            $type->save();

            $disabledDetail = $this->getJson($detailUrl)->assertOk();
            $disabledDetail->assertJsonPath("data.{$kind}_url", null);
            $this->assertSame($initialOtherPublicUrl, $disabledDetail->json("data.{$otherKind}_url"));
            $disabledAdmin = $this->getJson($adminUrl, $this->getHeaders())->assertOk();
            $disabledAdmin->assertJsonPath("data.{$kind}_url", null);
            $disabledAdmin->assertJsonMissingPath("data.admin_{$kind}_url");
            $this->assertSame($initialOtherPublicUrl, $disabledAdmin->json("data.{$otherKind}_url"));
            $this->assertCount(2, Storage::disk('public')->allFiles());

            $capabilities["has_{$kind}"]['value'] = true;
            $type->capabilities = $capabilities;
            $type->save();

            $restoredDetail = $this->getJson($detailUrl)->assertOk();
            $restoredAdmin = $this->getJson($adminUrl, $this->getHeaders())->assertOk();
            $this->assertSame($initialPublicUrl, $restoredDetail->json("data.{$kind}_url"));
            $this->assertSame($initialAdminUrl, $restoredAdmin->json("data.admin_{$kind}_url"));
            $this->get("{$this->base_api_tenant}media/account-profiles/{$profile->getKey()}/{$kind}")
                ->assertOk();
        }
    }

    public function test_protected_media_checks_tenant_and_view_ability_before_asset_resolution(): void
    {
        $stored = $this->profileWithStoredMedia('protected-guard-stored', published: true);
        $missing = $this->profileWithStoredMedia('protected-guard-missing', published: true, storeMedia: false);
        $user = LandlordUser::query()->firstOrFail();

        Sanctum::actingAs($user, []);
        foreach ([$stored, $missing] as $profile) {
            $this->get("{$this->base_tenant_api_admin}account_profiles/{$profile->getKey()}/media/avatar")
                ->assertForbidden();
        }

        $secondary = $this->ensureCanonicalTenantExists($this->landlord->tenant_secondary);
        $secondaryViewer = LandlordUser::query()->create([
            'name' => 'Secondary Media Viewer',
            'emails' => ['secondary-media-viewer@example.org'],
            'identity_state' => 'registered',
        ]);
        $secondaryViewer->tenant_roles = [[
            'name' => 'Secondary Media Viewer',
            'slug' => 'secondary-media-viewer',
            'permissions' => ['account-users:view'],
            'tenant_id' => (string) $secondary->getKey(),
        ]];
        $secondaryViewer->save();
        Sanctum::actingAs($secondaryViewer, ['account-users:view']);
        $foreignUrl = "http://{$secondary->subdomain}.{$this->host}/admin/api/v1/account_profiles/{$stored->getKey()}/media/avatar";
        $foreign = $this->get($foreignUrl);
        $foreign->assertNotFound();
        $this->assertNotInstanceOf(BinaryFileResponse::class, $foreign->baseResponse);
    }

    public function test_protected_media_rejects_a_real_anonymous_identity_bearer_without_bytes(): void
    {
        $profile = $this->profileWithStoredMedia('anonymous-protected-media', published: true);
        $token = $this->issueAnonymousIdentityToken();

        $this->app['auth']->forgetGuards();
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get("{$this->base_tenant_api_admin}account_profiles/{$profile->getKey()}/media/avatar");

        // Tenant-admin routes cross the landlord boundary before tenant ability checks.
        $response->assertUnauthorized();
        $this->assertNotInstanceOf(BinaryFileResponse::class, $response->baseResponse);
    }

    public function test_public_media_rejects_avatar_and_cover_when_the_parent_account_is_soft_deleted(): void
    {
        $profile = $this->profileWithStoredMedia('parent-deleted-public-media', published: true);
        Account::query()->findOrFail($profile->account_id)->delete();

        foreach (['avatar', 'cover'] as $kind) {
            $response = $this->get("{$this->base_api_tenant}media/account-profiles/{$profile->getKey()}/{$kind}");

            $response->assertNotFound();
            $this->assertNotInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        }
    }

    private function profileWithStoredMedia(string $type, bool $published, bool $storeMedia = true): AccountProfile
    {
        [$account] = $this->seedAccountWithRole(['account-users:view']);
        TenantProfileType::query()->create([
            'type' => $type,
            'label' => ucwords(str_replace('-', ' ', $type)),
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_publicly_discoverable' => ['value' => false, 'parameters' => []],
                'is_publicly_navigable' => ['value' => true, 'parameters' => []],
                'is_queryable' => ['value' => false, 'parameters' => []],
                'is_favoritable' => ['value' => false, 'parameters' => []],
                'is_map_poi_enabled' => ['value' => false, 'parameters' => []],
                'location_policy' => ['value' => 'disabled', 'parameters' => []],
                'is_physical_host_enabled' => ['value' => false, 'parameters' => []],
                'is_reference_location_enabled' => ['value' => false, 'parameters' => []],
                'has_events' => ['value' => false, 'parameters' => []],
                'has_avatar' => ['value' => true, 'parameters' => []],
                'has_cover' => ['value' => true, 'parameters' => []],
            ],
        ]);
        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->getKey(),
            'profile_type' => $type,
            'display_name' => ucwords(str_replace('-', ' ', $type)),
            'slug' => $type,
            'visibility' => 'public',
            'is_active' => true,
        ]);
        $baseUrl = rtrim($this->base_tenant_url, '/');
        $mediaService = app(\App\Application\AccountProfiles\AccountProfileMediaService::class);
        $profile->avatar_url = $mediaService->buildPublicUrl($baseUrl, $profile, 'avatar');
        $profile->cover_url = $mediaService->buildPublicUrl($baseUrl, $profile, 'cover');
        $profile->save();
        $profile = $profile->fresh();
        if (! $published) {
            $account->publication = ['status' => 'draft', 'publish_at' => null];
            $account->save();
        }

        $baseDirectory = app(\Belluga\Media\Application\ModelMediaService::class)
            ->resolveModelBaseDirectory($profile, new \Belluga\Media\Support\MediaModelDefinition(
                legacyPublicPathPrefix: '/account-profiles',
                canonicalPublicPathPrefix: '/api/v1/media/account-profiles',
                storageDirectory: 'account_profiles',
            ));
        if ($storeMedia) {
            Storage::disk('public')->put($baseDirectory.'/avatar.jpg', 'avatar-bytes');
            Storage::disk('public')->put($baseDirectory.'/cover.jpg', 'cover-bytes');
        }

        return $profile->fresh();
    }

    private function assertBinaryFileResponseBytes(TestResponse $response, string $expectedBytes): void
    {
        $baseResponse = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $baseResponse);
        $bytes = file_get_contents($baseResponse->getFile()->getPathname());
        $this->assertSame($expectedBytes, $bytes);
    }

    private function issueAnonymousIdentityToken(): string
    {
        $response = $this->postJson("{$this->base_api_tenant}anonymous/identities", [
            'device_name' => 'account-profile-media-authorization-test-device',
            'fingerprint' => [
                'hash' => hash('sha256', 'account-profile-media-authorization-test-device'),
                'user_agent' => 'AccountProfileMediaAuthorizationTest/1.0',
                'locale' => 'pt-BR',
            ],
            'metadata' => ['source' => 'feature-test'],
        ]);
        $response->assertCreated();

        return (string) $response->json('data.token');
    }
}
