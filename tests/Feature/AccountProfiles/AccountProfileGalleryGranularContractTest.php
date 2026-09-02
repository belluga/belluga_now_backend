<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileGalleryMutationService;
use App\Application\AccountProfiles\AccountProfileGalleryService;
use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\AccountProfiles\AccountProfileTypeSetProvider;
use App\Application\AccountProfiles\YoutubeVideoMetadataResolver;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Integration\Events\AccountProfileResolverAdapter;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use App\Support\Validation\InputConstraints;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

final class AccountProfileGalleryGranularContractTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get => $this->landlord->tenant_primary;
    }

    private static bool $bootstrapped = false;

    private Account $account;

    private int $transientProviderAttempts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }
        Tenant::query()->firstOrFail()->makeCurrent();
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:create', 'account-users:update', 'account-users:view']);
        AccountProfile::query()->delete();
        TenantProfileType::query()->delete();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'fallback001')) {
                return Http::response([], 503);
            }
            if (str_contains($request->url(), 'transient01')) {
                $this->transientProviderAttempts++;

                return $this->transientProviderAttempts === 1
                    ? Http::response([], 503)
                    : Http::response(['width' => 113, 'height' => 200]);
            }

            return Http::response(
                str_contains($request->url(), 'HKIZFC5HFtc')
                    ? ['width' => 113, 'height' => 200]
                    : ['width' => 200, 'height' => 113],
            );
        });
        [$this->account] = $this->seedAccountWithRole(['account-users:view', 'account-users:create', 'account-users:update', 'account-users:delete']);
        TenantProfileType::query()->create(['type' => 'venue', 'label' => 'Venue', 'allowed_taxonomies' => [], 'capabilities' => ['is_queryable' => true, 'is_publicly_navigable' => true, 'is_favoritable' => true, 'is_publicly_discoverable' => true, 'is_poi_enabled' => false, 'has_events' => true, 'has_gallery' => true]]);
    }

    public function test_granular_group_and_youtube_item_return_authoritative_envelope_and_canonical_identity(): void
    {
        $profile = $this->profile();
        $group = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Videos']);
        $group->assertOk()->assertJsonPath('data.gallery_capabilities.max_galleries', 6)->assertJsonCount(1, 'data.gallery_groups');
        $this->getJson($this->url($profile))->assertOk()->assertJsonPath('data.gallery_capabilities.max_items_per_gallery', 12);
        $groupId = (string) $group->json('data.gallery_groups.0.group_id');
        $item = $this->postJson($this->url($profile)."/gallery/groups/{$groupId}/items", ['type' => 'youtube', 'title' => 'Um minuto na praia', 'description' => 'Clip', 'youtube_url' => 'https://www.youtube.com/shorts/dQw4w9WgXcQ']);
        $item->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.type', 'youtube')->assertJsonPath('data.gallery_groups.0.items.0.title', 'Um minuto na praia')->assertJsonPath('data.gallery_groups.0.items.0.youtube_video_id', 'dQw4w9WgXcQ')->assertJsonMissingPath('data.gallery_groups.0.items.0.youtube_url');
    }

    public function test_aggregate_gallery_replacement_route_is_not_available(): void
    {
        $profile = $this->profile();
        $url = $this->url($profile).'/gallery';

        $this->patchJson($url, [
            'gallery_groups' => [],
        ])->assertNotFound();
        $this->withHeaders(['Accept' => 'application/json'])->post($url, [
            '_method' => 'PATCH',
            'gallery_groups' => [],
        ])->assertNotFound();
    }

    public function test_youtube_player_geometry_uses_provider_metadata_and_falls_back_without_rejecting_crud(): void
    {
        $profile = $this->profile();
        $group = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Videos'])->json('data.gallery_groups.0.group_id');
        $created = $this->postJson($this->url($profile)."/gallery/groups/{$group}/items", [
            'type' => 'youtube',
            'youtube_url' => 'https://www.youtube.com/watch?v=HKIZFC5HFtc',
        ]);
        $created->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.player_aspect_ratio', 0.565);

        $item = $created->json('data.gallery_groups.0.items.0.item_id');
        $this->patchJson($this->url($profile)."/gallery/groups/{$group}/items/{$item}", [
            'youtube_url' => 'https://www.youtube.com/watch?v=fallback001',
        ])->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.player_aspect_ratio', 1.777778);
    }

    public function test_youtube_player_geometry_retries_one_transient_provider_failure(): void
    {
        $profile = $this->profile();
        $group = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Videos'])->json('data.gallery_groups.0.group_id');

        $this->postJson($this->url($profile)."/gallery/groups/{$group}/items", [
            'type' => 'youtube',
            'youtube_url' => 'https://www.youtube.com/shorts/transient01',
        ])->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.player_aspect_ratio', 0.565);

        $this->assertSame(2, $this->transientProviderAttempts);
    }

    public function test_granular_reorder_requires_the_full_exact_id_set_and_profile_patch_rejects_gallery(): void
    {
        $profile = $this->profile();
        $first = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'One'])->json('data.gallery_groups.0.group_id');
        $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Two'])->assertOk();
        $this->patchJson($this->url($profile).'/gallery/groups/reorder', ['group_ids' => [$first]])->assertStatus(422)->assertJsonValidationErrors(['group_ids']);
        $this->patchJson($this->url($profile), ['gallery_groups' => []])->assertStatus(422)->assertJsonValidationErrors(['gallery_groups']);
    }

    public function test_invalid_youtube_hosts_userinfo_and_non_https_are_rejected(): void
    {
        $profile = $this->profile();
        $group = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Videos'])->json('data.gallery_groups.0.group_id');
        foreach (['http://youtu.be/dQw4w9WgXcQ', 'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ', 'https://user@youtube.com/watch?v=dQw4w9WgXcQ'] as $url) {
            $this->postJson($this->url($profile)."/gallery/groups/{$group}/items", ['type' => 'youtube', 'youtube_url' => $url])->assertStatus(422)->assertJsonValidationErrors(['youtube_url']);
        }
    }

    public function test_all_group_and_item_mutations_keep_empty_groups_and_contiguous_orders(): void
    {
        $profile = $this->profile();
        $first = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'First'])->json('data.gallery_groups.0.group_id');
        $second = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Second'])->json('data.gallery_groups.1.group_id');
        $this->patchJson($this->url($profile)."/gallery/groups/{$first}", ['subtitle' => 'Renamed'])->assertOk()->assertJsonPath('data.gallery_groups.0.subtitle', 'Renamed');
        $reordered = $this->patchJson($this->url($profile).'/gallery/groups/reorder', ['group_ids' => [$second, $first]]);
        $reordered->assertOk()->assertJsonPath('data.gallery_groups.0.order', 0);
        $item = $this->postJson($this->url($profile)."/gallery/groups/{$second}/items", ['type' => 'youtube', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ'])->json('data.gallery_groups.0.items.0.item_id');
        $this->patchJson($this->url($profile)."/gallery/groups/{$second}/items/{$item}", ['description' => 'Updated', 'type' => 'youtube'])->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.description', 'Updated');
        $this->patchJson($this->url($profile)."/gallery/groups/{$second}/items/reorder", ['item_ids' => [$item]])->assertOk();
        $this->deleteJson($this->url($profile)."/gallery/groups/{$second}/items/{$item}")->assertOk()->assertJsonCount(0, 'data.gallery_groups.0.items');
        $this->deleteJson($this->url($profile)."/gallery/groups/{$first}")->assertOk()->assertJsonCount(1, 'data.gallery_groups');
    }

    public function test_gallery_capability_fails_closed_for_every_mutation_without_erasing_dormant_data(): void
    {
        $profile = $this->profile();
        $profile->gallery_groups = [['group_id' => 'dormant', 'subtitle' => 'Dormant', 'order' => 0, 'items' => []]];
        $profile->save();
        TenantProfileType::query()->where('type', 'venue')->update(['capabilities' => ['has_gallery' => false]]);
        $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Blocked'])->assertStatus(422)->assertJsonValidationErrors(['gallery_groups']);
        $this->patchJson($this->url($profile).'/gallery/groups/dormant', ['subtitle' => 'Blocked'])->assertStatus(422);
        $this->deleteJson($this->url($profile).'/gallery/groups/dormant')->assertStatus(422);
        $this->patchJson($this->url($profile).'/gallery/groups/reorder', ['group_ids' => ['dormant']])->assertStatus(422);
        $this->postJson($this->url($profile).'/gallery/groups/dormant/items', ['type' => 'youtube', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertStatus(422);
        $this->patchJson($this->url($profile).'/gallery/groups/dormant/items/dormant-item', ['description' => 'Blocked'])->assertStatus(422)->assertJsonValidationErrors(['gallery_groups']);
        $this->deleteJson($this->url($profile).'/gallery/groups/dormant/items/dormant-item')->assertStatus(422)->assertJsonValidationErrors(['gallery_groups']);
        $this->patchJson($this->url($profile).'/gallery/groups/dormant/items/reorder', ['item_ids' => ['dormant-item']])->assertStatus(422)->assertJsonValidationErrors(['gallery_groups']);
        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $stored = AccountProfile::query()->findOrFail($profile->getKey());
        $this->assertSame('dormant', $stored->gallery_groups[0]['group_id']);
    }

    public function test_gallery_mutations_require_the_account_users_update_ability(): void
    {
        $profile = $this->profile();
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);

        $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Forbidden'])
            ->assertForbidden();

        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $this->assertSame([], AccountProfile::query()->findOrFail($profile->getKey())->gallery_groups ?? []);
    }

    public function test_capacity_blocks_only_the_corresponding_create_with_stable_field_keys(): void
    {
        config(['gallery.max_galleries' => 1, 'gallery.max_items_per_gallery' => 1]);
        $profile = $this->profile();
        $group = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Only'])->json('data.gallery_groups.0.group_id');
        $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Blocked'])->assertStatus(422)->assertJsonValidationErrors(['gallery_capabilities.max_galleries']);
        $this->postJson($this->url($profile)."/gallery/groups/{$group}/items", ['type' => 'youtube', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertOk();
        $this->postJson($this->url($profile)."/gallery/groups/{$group}/items", ['type' => 'youtube', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertStatus(422)->assertJsonValidationErrors(['gallery_capabilities.max_items_per_gallery']);
    }

    public function test_reorders_require_exact_membership_and_unknown_group_or_item_is_not_found(): void
    {
        $profile = $this->profile();
        $first = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'One'])->json('data.gallery_groups.0.group_id');
        $second = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Two'])->json('data.gallery_groups.1.group_id');
        foreach ([[$first], [$first, $first], [$first, 'foreign']] as $ids) {
            $this->patchJson($this->url($profile).'/gallery/groups/reorder', ['group_ids' => $ids])->assertStatus(422)->assertJsonValidationErrors(['group_ids']);
        }
        $item = $this->postJson($this->url($profile)."/gallery/groups/{$first}/items", ['type' => 'youtube', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ'])->json('data.gallery_groups.0.items.0.item_id');
        foreach ([[], [$item, $item], ['foreign']] as $ids) {
            $this->patchJson($this->url($profile)."/gallery/groups/{$first}/items/reorder", ['item_ids' => $ids])->assertStatus(422)->assertJsonValidationErrors(['item_ids']);
        }
        $this->patchJson($this->url($profile).'/gallery/groups/missing', ['subtitle' => 'Missing'])->assertNotFound();
        $this->deleteJson($this->url($profile)."/gallery/groups/{$second}/items/missing")->assertNotFound();
        $this->getJson($this->url($profile).'/missing')->assertNotFound();
    }

    public function test_item_patch_preserves_or_clears_title_and_description_and_rejects_type_or_provider_changes(): void
    {
        $profile = $this->profile();
        $group = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Mixed'])->json('data.gallery_groups.0.group_id');
        $item = $this->postJson($this->url($profile)."/gallery/groups/{$group}/items", ['type' => 'youtube', 'title' => 'Original title', 'description' => 'Original', 'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])->json('data.gallery_groups.0.items.0.item_id');
        $base = $this->url($profile)."/gallery/groups/{$group}/items/{$item}";
        $this->patchJson($base, ['type' => 'youtube'])->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.title', 'Original title')->assertJsonPath('data.gallery_groups.0.items.0.description', 'Original');
        $this->patchJson($base, [])->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.title', 'Original title')->assertJsonPath('data.gallery_groups.0.items.0.description', 'Original');
        $this->patchJson($base, ['title' => 'Updated title'])->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.title', 'Updated title')->assertJsonPath('data.gallery_groups.0.items.0.description', 'Original');
        $this->patchJson($base, ['title' => null])->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.title', null);
        $this->patchJson($base, ['title' => ''])->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.title', null);
        $this->patchJson($base, ['title' => str_repeat('x', 256)])->assertStatus(422)->assertJsonValidationErrors(['title']);
        $this->patchJson($base, ['description' => null])->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.description', null);
        $this->patchJson($base, ['description' => ''])->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.description', null);
        $this->patchJson($base, ['type' => 'photo'])->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->withHeaders(['Accept' => 'application/json'])->post($base, ['_method' => 'PATCH', 'image' => \Illuminate\Http\UploadedFile::fake()->image('invalid.jpg')])->assertStatus(422)->assertJsonValidationErrors(['image']);
    }

    public function test_youtube_url_admission_and_rejection_are_strict(): void
    {
        $profile = $this->profile();
        $group = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Videos'])->json('data.gallery_groups.0.group_id');
        foreach (['https://youtu.be/dQw4w9WgXcQ', 'https://youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'https://m.youtube.com/embed/dQw4w9WgXcQ'] as $url) {
            $this->postJson($this->url($profile)."/gallery/groups/{$group}/items", ['type' => 'youtube', 'youtube_url' => $url])->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.youtube_video_id', 'dQw4w9WgXcQ');
        }
        foreach (['https://youtube.com/watch?v=too-short', 'https://youtube.com/playlist?list=x', 'https://youtu.be/dQw4w9WgXcQ/extra'] as $url) {
            $this->postJson($this->url($profile)."/gallery/groups/{$group}/items", ['type' => 'youtube', 'youtube_url' => $url])->assertStatus(422)->assertJsonValidationErrors(['youtube_url']);
        }
        $this->postJson($this->url($profile)."/gallery/groups/{$group}/items", [
            'type' => 'youtube',
            'youtube_url' => 'https://youtube.com/watch?v=dQw4w9WgXcQ&padding='.str_repeat('a', 2048),
        ])->assertStatus(422)->assertJsonValidationErrors(['youtube_url']);
    }

    public function test_gallery_photo_uploads_enforce_the_canonical_size_and_format_bounds_on_create_and_update(): void
    {
        $profile = $this->profile();
        $group = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Photos'])->json('data.gallery_groups.0.group_id');
        $itemsUrl = $this->url($profile)."/gallery/groups/{$group}/items";

        $this->withHeaders(['Accept' => 'application/json'])->post($itemsUrl, [
            'type' => 'photo',
            'image' => UploadedFile::fake()->image('oversized.jpg')->size(InputConstraints::IMAGE_MAX_KB + 1),
        ])->assertStatus(422)->assertJsonValidationErrors(['image']);

        $this->withHeaders(['Accept' => 'application/json'])->post($itemsUrl, [
            'type' => 'photo',
            'image' => UploadedFile::fake()->image('unsupported.gif'),
        ])->assertStatus(422)->assertJsonValidationErrors(['image']);

        $created = $this->withHeaders(['Accept' => 'application/json'])->post($itemsUrl, [
            'type' => 'photo',
            'title' => 'Vista principal',
            'image' => UploadedFile::fake()->image('valid.png'),
        ])->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.title', 'Vista principal');
        $item = $created->json('data.gallery_groups.0.items.0.item_id');

        $this->getJson($this->url($profile))
            ->assertOk()
            ->assertJsonPath('data.gallery_groups.0.items.0.title', 'Vista principal');
        $this->getJson($this->base_api_tenant.'account_profiles/'.$profile->slug, $this->getHeaders())
            ->assertOk()
            ->assertJsonPath('data.gallery_groups.0.items.0.title', 'Vista principal');

        $this->withHeaders(['Accept' => 'application/json'])->post("{$itemsUrl}/{$item}", [
            '_method' => 'PATCH',
            'image' => UploadedFile::fake()->image('replacement.jpg')->size(InputConstraints::IMAGE_MAX_KB + 1),
        ])->assertStatus(422)->assertJsonValidationErrors(['image']);
    }

    public function test_group_delete_rollback_restores_photos_from_the_authoritative_transaction_state(): void
    {
        Storage::fake('public');
        $stale = $this->profile();
        $stale->gallery_groups = [[
            'group_id' => 'target',
            'subtitle' => 'Target',
            'order' => 0,
            'items' => [[
                'item_id' => 'old-photo',
                'type' => 'photo',
                'order' => 0,
            ]],
        ]];
        $authoritative = clone $stale;
        $authoritative->gallery_groups = [[
            'group_id' => 'target',
            'subtitle' => 'Target',
            'order' => 0,
            'items' => [
                ['item_id' => 'old-photo', 'type' => 'photo', 'order' => 0],
                ['item_id' => 'new-photo', 'type' => 'photo', 'order' => 1],
            ],
        ]];

        $baseUrl = 'https://tenant-zeta.test';
        $media = app(AccountProfileMediaService::class);
        $media->storeGalleryUpload($baseUrl, $authoritative, 'old-photo', UploadedFile::fake()->image('old.jpg'));
        $media->storeGalleryUpload($baseUrl, $authoritative, 'new-photo', UploadedFile::fake()->image('new.jpg'));
        $before = collect(Storage::disk('public')->allFiles())
            ->mapWithKeys(static fn (string $path): array => [$path => Storage::disk('public')->get($path)])
            ->all();
        $this->assertNotEmpty($before);

        $profiles = Mockery::mock(AccountProfileManagementService::class);
        $profiles->shouldReceive('update')->once()->andReturnUsing(
            static function (
                AccountProfile $profile,
                array $attributes,
                ?string $commandId,
                ?\Closure $mutateWithinTransaction,
                array $fingerprintSupplement,
                bool $dispatchOutboxImmediately,
                ?\Closure $compensateKnownRollback,
                bool $useAggregateRevisionCas,
            ) use ($authoritative): AccountProfile {
                try {
                    $firstAttempt = clone $authoritative;
                    $mutateWithinTransaction?->__invoke($firstAttempt);
                    $secondAttempt = clone $authoritative;
                    $mutateWithinTransaction?->__invoke($secondAttempt);
                    throw new RuntimeException('Forced rollback after retried authoritative gallery mutation.');
                } catch (RuntimeException $exception) {
                    $compensateKnownRollback?->__invoke();

                    throw $exception;
                }
            },
        );
        $mutations = new AccountProfileGalleryMutationService(
            new AccountProfileGalleryService($media, app(AccountProfileTypeSetProvider::class)),
            $profiles,
            $media,
            app(AccountProfileTypeSetProvider::class),
            app(YoutubeVideoMetadataResolver::class),
        );

        try {
            $mutations->deleteGroup($stale, 'target', $baseUrl);
            $this->fail('The forced rollback must escape the mutation service.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced rollback after retried authoritative gallery mutation.', $exception->getMessage());
        }

        $after = collect(Storage::disk('public')->allFiles())
            ->mapWithKeys(static fn (string $path): array => [$path => Storage::disk('public')->get($path)])
            ->all();
        $this->assertSame($before, $after);
    }

    public function test_account_store_and_patch_reject_gallery_but_an_unrelated_patch_preserves_it(): void
    {
        $profile = $this->profile();
        $profile->gallery_groups = [['group_id' => 'retained', 'subtitle' => 'Retained', 'order' => 0, 'items' => []]];
        $profile->save();
        $this->patchJson($this->url($profile), ['gallery_groups' => []])->assertStatus(422)->assertJsonValidationErrors(['gallery_groups']);
        $this->patchJson($this->url($profile), ['display_name' => 'Preserved Gallery'])
            ->assertOk()
            ->assertJsonMissingPath('data.gallery_capabilities');
        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $this->assertSame('retained', AccountProfile::query()->findOrFail($profile->getKey())->gallery_groups[0]['group_id']);
        $this->postJson($this->base_tenant_api_admin.'account_profiles', ['gallery_groups' => [['group_id' => 'obsolete']]])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'tenant_admin_onboarding_required');
    }

    public function test_admin_keeps_empty_groups_public_omits_them_and_reduced_limits_do_not_hide_retained_content(): void
    {
        config(['gallery.max_galleries' => 2, 'gallery.max_items_per_gallery' => 2]);
        $profile = $this->profile();
        $empty = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Empty'])->assertOk();
        $populated = $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Published'])->assertOk();
        $group = $populated->json('data.gallery_groups.1.group_id');
        $this->postJson($this->url($profile)."/gallery/groups/{$group}/items", ['type' => 'youtube', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertOk();
        $this->getJson($this->url($profile))->assertOk()->assertJsonCount(2, 'data.gallery_groups');
        $this->getJson($this->base_api_tenant.'account_profiles/'.$profile->slug, $this->getHeaders())->assertOk()->assertJsonCount(1, 'data.gallery_groups');
        config(['gallery.max_galleries' => 1, 'gallery.max_items_per_gallery' => 0]);
        $this->getJson($this->base_api_tenant.'account_profiles/'.$profile->slug, $this->getHeaders())->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.type', 'youtube');
        $this->postJson($this->url($profile).'/gallery/groups', ['subtitle' => 'Blocked'])->assertStatus(422)->assertJsonValidationErrors(['gallery_capabilities.max_galleries']);
        $this->deleteJson($this->url($profile).'/gallery/groups/'.$empty->json('data.gallery_groups.0.group_id'))->assertOk();
    }

    public function test_legacy_item_without_a_type_reads_as_a_photo(): void
    {
        $profile = $this->profile();
        $profile->gallery_groups = [['group_id' => 'legacy', 'subtitle' => 'Legacy', 'order' => 0, 'items' => [['item_id' => 'legacy-photo', 'description' => 'Legacy photo', 'order' => 0, 'media_path' => '/api/v1/media/account-profiles/'.$profile->getKey().'/gallery/legacy-photo', 'version' => '1']]]];
        $profile->save();
        $this->getJson($this->url($profile))->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.type', 'photo');
        $this->getJson($this->base_api_tenant.'account_profiles/'.$profile->slug, $this->getHeaders())->assertOk()->assertJsonPath('data.gallery_groups.0.items.0.type', 'photo');
    }

    public function test_event_profile_projection_keeps_photos_omits_youtube_and_honors_gallery_capability(): void
    {
        $capabilities = [
            'is_queryable' => true,
            'is_publicly_navigable' => true,
            'is_favoritable' => true,
            'is_publicly_discoverable' => true,
            'is_poi_enabled' => true,
            'has_events' => true,
            'has_gallery' => true,
        ];
        TenantProfileType::query()->where('type', 'venue')->update(['capabilities' => $capabilities]);
        $profile = $this->profile();
        $profile->location = ['type' => 'Point', 'coordinates' => [-40.0, -20.0]];
        $profile->gallery_groups = [[
            'group_id' => 'mixed',
            'subtitle' => 'Mixed',
            'order' => 0,
            'items' => [
                ['item_id' => 'photo', 'type' => 'photo', 'order' => 0, 'media_path' => '/gallery/photo', 'version' => '1'],
                ['item_id' => 'youtube', 'type' => 'youtube', 'order' => 1, 'youtube_video_id' => 'dQw4w9WgXcQ'],
            ],
        ]];
        $profile->save();

        $resolver = app(AccountProfileResolverAdapter::class);
        $resolved = $resolver->resolvePhysicalHostByProfileId((string) $profile->getKey());
        $this->assertSame('photo', data_get($resolved, 'venue.gallery_groups.0.items.0.type'));
        $this->assertCount(1, data_get($resolved, 'venue.gallery_groups.0.items', []));

        TenantProfileType::query()->where('type', 'venue')->update(['capabilities' => [...$capabilities, 'has_gallery' => false]]);
        AccountProfileTypeSetProvider::bumpRevision();
        $resolved = $resolver->resolvePhysicalHostByProfileId((string) $profile->getKey());
        $this->assertSame([], data_get($resolved, 'venue.gallery_groups'));
    }

    private function profile(): AccountProfile
    {
        return AccountProfile::query()->create(['account_id' => (string) $this->account->getKey(), 'profile_type' => 'venue', 'display_name' => 'Gallery Contract', 'slug' => 'gallery-contract', 'visibility' => 'public', 'is_active' => true])->fresh();
    }

    private function url(AccountProfile $profile): string
    {
        return $this->base_tenant_api_admin.'account_profiles/'.$profile->getKey();
    }

    private function initializeSystem(): void
    {
        app(SystemInitializationService::class)->initialize(new InitializationPayload(landlord: ['name' => 'Landlord HQ'], tenant: ['name' => $this->tenant->name, 'subdomain' => $this->tenant->subdomain], role: ['name' => 'Root', 'permissions' => ['*']], user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'], themeDataSettings: ['brightness_default' => 'light', 'primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'], logoSettings: ['light_logo_uri' => '/logos/light.png'], pwaIcon: ['icon192_uri' => '/pwa/icon192.png'], tenantDomains: [$this->tenant->subdomain.'.'.$this->host]));
    }
}
