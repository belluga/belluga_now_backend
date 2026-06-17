<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

final class AccountProfileGalleryControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
        AccountProfile::query()->delete();
        TenantProfileType::query()->delete();

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);

        TenantProfileType::query()->updateOrCreate(
            ['type' => 'venue'],
            ['label' => 'Venue',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_publicly_navigable' => true,
                    'is_favoritable' => true,
                    'is_publicly_discoverable' => true,
                    'is_poi_enabled' => false,
                    'has_events' => true,
                    'has_gallery' => true,
                ],
            ],
        );
    }

    public function test_gallery_subresource_persists_ordered_groups_variants_and_public_readback(): void
    {
        Storage::fake('public');

        $profile = $this->createPublicProfile('Gallery Parent', 'gallery-parent');

        $response = $this->patchGallery(
            $profile,
            [
                [
                    'group_id' => 'facade',
                    'subtitle' => 'Fachada',
                    'order' => 1,
                    'items' => [
                        [
                            'item_id' => 'entrada',
                            'order' => 1,
                            'description' => 'Entrada principal',
                            'upload' => 'upload_entrada',
                        ],
                        [
                            'item_id' => 'externa',
                            'order' => 0,
                            'description' => 'Vista externa',
                            'upload' => 'upload_externa',
                        ],
                    ],
                ],
                [
                    'group_id' => 'salas',
                    'subtitle' => 'Salas',
                    'order' => 0,
                    'items' => [
                        [
                            'item_id' => 'palco',
                            'order' => 0,
                            'description' => 'Palco ao pôr do sol',
                            'upload' => 'upload_palco',
                        ],
                    ],
                ],
            ],
            [
                'upload_entrada' => UploadedFile::fake()->image('entrada.png', 1600, 900),
                'upload_externa' => UploadedFile::fake()->image('externa.jpg', 3000, 2000),
                'upload_palco' => UploadedFile::fake()->image('palco.jpg', 2800, 1800),
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('data.gallery_groups.0.group_id', 'salas');
        $response->assertJsonPath('data.gallery_groups.0.subtitle', 'Salas');
        $response->assertJsonPath('data.gallery_groups.0.order', 0);
        $response->assertJsonPath('data.gallery_groups.1.group_id', 'facade');
        $response->assertJsonPath('data.gallery_groups.1.items.0.item_id', 'externa');
        $response->assertJsonPath('data.gallery_groups.1.items.0.order', 0);
        $response->assertJsonPath('data.gallery_groups.1.items.1.item_id', 'entrada');
        $response->assertJsonPath('data.gallery_groups.1.items.1.order', 1);

        $imageUrl = (string) $response->json('data.gallery_groups.1.items.0.image_url');
        $thumbUrl = (string) $response->json('data.gallery_groups.1.items.0.thumb_url');
        $cardUrl = (string) $response->json('data.gallery_groups.1.items.0.card_url');
        $modalUrl = (string) $response->json('data.gallery_groups.1.items.0.modal_url');
        $canonicalPath = "/api/v1/media/account-profiles/{$profile->getKey()}/gallery/externa";

        $this->assertSame($canonicalPath, parse_url($imageUrl, PHP_URL_PATH));
        $this->assertSame($canonicalPath, parse_url($thumbUrl, PHP_URL_PATH));
        $this->assertSame($canonicalPath, parse_url($cardUrl, PHP_URL_PATH));
        $this->assertSame($canonicalPath, parse_url($modalUrl, PHP_URL_PATH));
        parse_str((string) parse_url($imageUrl, PHP_URL_QUERY), $imageQuery);
        parse_str((string) parse_url($thumbUrl, PHP_URL_QUERY), $thumbQuery);
        $this->assertSame(
            app(AccountProfileMediaService::class)->defaultGalleryVariant(),
            $imageQuery['variant'] ?? null
        );
        $this->assertSame('thumb', $thumbQuery['variant'] ?? null);
        $this->assertNotSame('', (string) ($thumbQuery['v'] ?? ''));

        $freshProfile = AccountProfile::query()->findOrFail($profile->getKey());
        $storedItem = $this->storedGalleryItem($freshProfile, 'externa');
        $this->assertNotNull($storedItem);
        $this->assertSame($canonicalPath, $storedItem['media_path'] ?? null);
        $this->assertDoesNotMatchRegularExpression('#^https?://#', (string) ($storedItem['media_path'] ?? ''));
        $this->assertNotSame('', (string) ($storedItem['version'] ?? ''));

        $masterPath = $this->assertGalleryVariantStored($profile, 'externa', null);
        $thumbPath = $this->assertGalleryVariantStored($profile, 'externa', 'thumb');
        $cardPath = $this->assertGalleryVariantStored($profile, 'externa', 'card');
        $modalPath = $this->assertGalleryVariantStored($profile, 'externa', 'modal');

        $this->assertLongestEdgeAtMost($masterPath, 2048);
        $this->assertLongestEdgeAtMost($thumbPath, 320);
        $this->assertLongestEdgeAtMost($cardPath, 960);
        $this->assertLongestEdgeAtMost($modalPath, 1600);
        $this->assertAspectRatioPreserved($masterPath, 3000 / 2000);

        $this->get($thumbUrl)->assertOk()->assertHeader('ETag');
        $this->get($modalUrl)->assertOk()->assertHeader('ETag');

        $publicReadback = $this->getJson(
            "{$this->base_api_tenant}account_profiles/gallery-parent",
            $this->getHeaders()
        );
        $publicReadback->assertOk();
        $publicReadback->assertJsonPath('data.gallery_groups.0.group_id', 'salas');
        $publicReadback->assertJsonPath('data.gallery_groups.1.items.0.item_id', 'externa');
        $publicReadback->assertJsonPath(
            'data.gallery_groups.1.items.0.modal_url',
            $modalUrl
        );
    }

    public function test_gallery_subresource_rejects_non_empty_payload_when_type_capability_is_disabled(): void
    {
        Storage::fake('public');

        TenantProfileType::query()->updateOrCreate(
            ['type' => 'plain'],
            ['label' => 'Plain',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_publicly_navigable' => true,
                    'is_favoritable' => false,
                    'is_publicly_discoverable' => true,
                    'is_poi_enabled' => false,
                    'has_events' => false,
                    'has_gallery' => false,
                ],
            ],
        );

        $profile = $this->createPublicProfile(
            'Gallery Disabled Parent',
            'gallery-disabled-parent',
            profileType: 'plain',
        );

        $response = $this->patchGallery(
            $profile,
            [
                [
                    'group_id' => 'facade',
                    'subtitle' => 'Fachada',
                    'items' => [
                        [
                            'item_id' => 'entrada',
                            'upload' => 'upload_entrada',
                        ],
                    ],
                ],
            ],
            [
                'upload_entrada' => UploadedFile::fake()->image('entrada.png', 1200, 800),
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['gallery_groups']);
    }

    public function test_public_detail_hides_gallery_when_type_capability_is_disabled(): void
    {
        Storage::fake('public');

        TenantProfileType::query()->updateOrCreate(
            ['type' => 'plain'],
            ['label' => 'Plain',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_publicly_navigable' => true,
                    'is_favoritable' => false,
                    'is_publicly_discoverable' => true,
                    'is_poi_enabled' => false,
                    'has_events' => false,
                    'has_gallery' => true,
                ],
            ],
        );

        $profile = $this->createPublicProfile(
            'Gallery Hidden Parent',
            'gallery-hidden-parent',
            profileType: 'plain',
        );

        $this->patchGallery(
            $profile,
            [
                [
                    'group_id' => 'facade',
                    'subtitle' => 'Fachada',
                    'order' => 0,
                    'items' => [
                        [
                            'item_id' => 'entrada',
                            'description' => 'Entrada principal',
                            'order' => 0,
                            'upload' => 'upload_entrada',
                        ],
                    ],
                ],
            ],
            [
                'upload_entrada' => UploadedFile::fake()->image('entrada.png', 1200, 800),
            ],
        )->assertOk();

        // Phase 1: gallery is exposed while the type capability is enabled.
        $profile = $profile->fresh();
        $this->assertGalleryVariantStored($profile, 'entrada', null);
        $this->assertGalleryVariantStored($profile, 'entrada', 'modal');

        $mediaRoute = "api/v1/media/account-profiles/{$profile->getKey()}/gallery/entrada?variant=modal";
        $this->get($mediaRoute, $this->getHeaders())->assertOk();

        // Phase 2: disabling the type capability hides public readback and media access
        // without deleting the stored gallery payload.
        TenantProfileType::query()->updateOrCreate(
            ['type' => 'plain'],
            ['label' => 'Plain',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_publicly_navigable' => true,
                    'is_favoritable' => false,
                    'is_publicly_discoverable' => true,
                    'is_poi_enabled' => false,
                    'has_events' => false,
                    'has_gallery' => false,
                ],
            ],
        );

        $persistedProfile = AccountProfile::query()->findOrFail($profile->getKey());
        $persistedItem = $this->storedGalleryItem($persistedProfile, 'entrada');
        $this->assertNotNull($persistedItem);
        $this->assertSame('Entrada principal', $persistedItem['description'] ?? null);

        $publicReadback = $this->getJson(
            "{$this->base_api_tenant}account_profiles/gallery-hidden-parent",
            $this->getHeaders()
        );
        $publicReadback->assertOk();
        $publicReadback->assertJsonPath('data.gallery_groups', []);

        $this->get($mediaRoute, $this->getHeaders())->assertNotFound();

        // Phase 3: re-enabling the capability restores public exposure of the dormant data.
        TenantProfileType::query()->updateOrCreate(
            ['type' => 'plain'],
            ['label' => 'Plain',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_publicly_navigable' => true,
                    'is_favoritable' => false,
                    'is_publicly_discoverable' => true,
                    'is_poi_enabled' => false,
                    'has_events' => false,
                    'has_gallery' => true,
                ],
            ],
        );

        $reEnabledReadback = $this->getJson(
            "{$this->base_api_tenant}account_profiles/gallery-hidden-parent",
            $this->getHeaders()
        );
        $reEnabledReadback->assertOk();
        $reEnabledReadback->assertJsonPath('data.gallery_groups.0.items.0.item_id', 'entrada');
        $this->get($mediaRoute, $this->getHeaders())->assertOk();
    }

    public function test_public_gallery_media_requires_active_public_navigable_profile(): void
    {
        Storage::fake('public');

        $profile = $this->createPublicProfile('Gallery Media Gate Parent', 'gallery-media-gate-parent');

        $this->patchGallery(
            $profile,
            [
                [
                    'group_id' => 'facade',
                    'subtitle' => 'Fachada',
                    'order' => 0,
                    'items' => [
                        [
                            'item_id' => 'entrada',
                            'description' => 'Entrada principal',
                            'order' => 0,
                            'upload' => 'upload_entrada',
                        ],
                    ],
                ],
            ],
            [
                'upload_entrada' => UploadedFile::fake()->image('entrada.png', 1200, 800),
            ],
        )->assertOk();

        $profile = $profile->fresh();
        $mediaRoute = "api/v1/media/account-profiles/{$profile->getKey()}/gallery/entrada?variant=modal";

        $this->get($mediaRoute, $this->getHeaders())->assertOk();

        $profile->visibility = 'friends_only';
        $profile->save();
        $this->get($mediaRoute, $this->getHeaders())->assertNotFound();

        $profile->visibility = 'public';
        $profile->is_active = false;
        $profile->save();
        $this->get($mediaRoute, $this->getHeaders())->assertNotFound();

        $profile->is_active = true;
        $profile->save();
        TenantProfileType::query()->updateOrCreate(
            ['type' => 'venue'],
            ['label' => 'Venue',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_publicly_navigable' => false,
                    'is_favoritable' => true,
                    'is_publicly_discoverable' => true,
                    'is_poi_enabled' => false,
                    'has_events' => true,
                    'has_gallery' => true,
                ],
            ],
        );
        $this->get($mediaRoute, $this->getHeaders())->assertNotFound();

        TenantProfileType::query()->updateOrCreate(
            ['type' => 'venue'],
            ['label' => 'Venue',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_publicly_navigable' => true,
                    'is_favoritable' => true,
                    'is_publicly_discoverable' => true,
                    'is_poi_enabled' => false,
                    'has_events' => true,
                    'has_gallery' => true,
                ],
            ],
        );
        $this->get($mediaRoute, $this->getHeaders())->assertOk();
    }

    public function test_gallery_subresource_reorders_replaces_and_removes_groups_and_items(): void
    {
        Storage::fake('public');

        $profile = $this->createPublicProfile('Gallery Replace Parent', 'gallery-replace-parent');

        $this->patchGallery(
            $profile,
            [
                [
                    'group_id' => 'alpha',
                    'subtitle' => 'Alpha',
                    'order' => 0,
                    'items' => [
                        ['item_id' => 'one', 'order' => 0, 'upload' => 'upload_one'],
                        ['item_id' => 'two', 'order' => 1, 'upload' => 'upload_two'],
                    ],
                ],
                [
                    'group_id' => 'beta',
                    'subtitle' => 'Beta',
                    'order' => 1,
                    'items' => [
                        ['item_id' => 'three', 'order' => 0, 'upload' => 'upload_three'],
                    ],
                ],
            ],
            [
                'upload_one' => UploadedFile::fake()->image('one.png', 1400, 900),
                'upload_two' => UploadedFile::fake()->image('two.png', 1500, 900),
                'upload_three' => UploadedFile::fake()->image('three.png', 1600, 900),
            ],
        )->assertOk();

        $originalProfile = AccountProfile::query()->findOrFail($profile->getKey());
        $originalOne = $this->storedGalleryItem($originalProfile, 'one');
        $originalTwoMasterPath = $this->assertGalleryVariantStored($originalProfile, 'two', null);
        $originalTwoThumbPath = $this->assertGalleryVariantStored($originalProfile, 'two', 'thumb');
        $originalTwoCardPath = $this->assertGalleryVariantStored($originalProfile, 'two', 'card');
        $originalTwoModalPath = $this->assertGalleryVariantStored($originalProfile, 'two', 'modal');
        $originalOneMasterPath = $this->assertGalleryVariantStored($originalProfile, 'one', null);
        $originalOneThumbPath = $this->assertGalleryVariantStored($originalProfile, 'one', 'thumb');
        $originalOneCardPath = $this->assertGalleryVariantStored($originalProfile, 'one', 'card');
        $originalOneModalPath = $this->assertGalleryVariantStored($originalProfile, 'one', 'modal');
        $this->assertNotNull($originalOne);

        $response = $this->patchGallery(
            $profile,
            [
                [
                    'group_id' => 'beta',
                    'subtitle' => 'Beta',
                    'order' => 0,
                    'items' => [
                        ['item_id' => 'three', 'order' => 0],
                        [
                            'item_id' => 'one',
                            'order' => 1,
                            'description' => 'Imagem substituída',
                            'upload' => 'upload_one_replacement',
                        ],
                    ],
                ],
            ],
            [
                'upload_one_replacement' => UploadedFile::fake()->image('one.jpg', 1800, 1200),
            ],
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data.gallery_groups');
        $response->assertJsonPath('data.gallery_groups.0.group_id', 'beta');
        $response->assertJsonPath('data.gallery_groups.0.items.0.item_id', 'three');
        $response->assertJsonPath('data.gallery_groups.0.items.1.item_id', 'one');
        $response->assertJsonPath('data.gallery_groups.0.items.1.description', 'Imagem substituída');

        Storage::disk('public')->assertMissing($originalTwoMasterPath);
        Storage::disk('public')->assertMissing($originalTwoThumbPath);
        Storage::disk('public')->assertMissing($originalTwoCardPath);
        Storage::disk('public')->assertMissing($originalTwoModalPath);
        Storage::disk('public')->assertMissing($originalOneMasterPath);
        Storage::disk('public')->assertMissing($originalOneThumbPath);
        Storage::disk('public')->assertMissing($originalOneCardPath);
        Storage::disk('public')->assertMissing($originalOneModalPath);

        $reloadedProfile = AccountProfile::query()->findOrFail($profile->getKey());
        $updatedOne = $this->storedGalleryItem($reloadedProfile, 'one');
        $this->assertNotNull($updatedOne);
        $this->assertNotSame(
            (string) ($originalOne['version'] ?? ''),
            (string) ($updatedOne['version'] ?? '')
        );
        $this->assertNull($this->storedGalleryItem($reloadedProfile, 'two'));
        $this->assertGalleryVariantStored($reloadedProfile, 'one', null);
        $this->assertGalleryVariantStored($reloadedProfile, 'one', 'thumb');
        $this->assertGalleryVariantStored($reloadedProfile, 'one', 'card');
        $this->assertGalleryVariantStored($reloadedProfile, 'one', 'modal');
        $this->get(
            (string) $response->json('data.gallery_groups.0.items.1.modal_url'),
            $this->getHeaders()
        )->assertOk();
    }

    public function test_gallery_subresource_rejects_empty_groups_missing_uploads_group_limit_and_total_item_limit(): void
    {
        Storage::fake('public');

        $profile = $this->createPublicProfile('Gallery Validation Parent', 'gallery-validation-parent');

        $emptyGroup = $this->patchGallery(
            $profile,
            [
                [
                    'group_id' => 'empty',
                    'subtitle' => 'Empty',
                    'items' => [],
                ],
            ],
        );
        $emptyGroup->assertStatus(422);
        $emptyGroup->assertJsonValidationErrors(['gallery_groups.0.items']);

        $missingUpload = $this->patchGallery(
            $profile,
            [
                [
                    'group_id' => 'missing-upload',
                    'subtitle' => 'Missing Upload',
                    'items' => [
                        ['item_id' => 'new-item'],
                    ],
                ],
            ],
        );
        $missingUpload->assertStatus(422);
        $missingUpload->assertJsonValidationErrors(['gallery_groups.0.items.0.upload']);

        $tooManyGroupsPayload = [];
        $tooManyGroupFiles = [];
        for ($groupIndex = 0; $groupIndex <= 6; $groupIndex++) {
            $uploadKey = "group_file_{$groupIndex}";
            $tooManyGroupsPayload[] = [
                'group_id' => "group-{$groupIndex}",
                'subtitle' => "Group {$groupIndex}",
                'items' => [
                    [
                        'item_id' => "item-{$groupIndex}",
                        'upload' => $uploadKey,
                    ],
                ],
            ];
            $tooManyGroupFiles[$uploadKey] = UploadedFile::fake()->image("group-{$groupIndex}.png", 800, 600);
        }

        $tooManyGroups = $this->patchGallery($profile, $tooManyGroupsPayload, $tooManyGroupFiles);
        $tooManyGroups->assertStatus(422);
        $tooManyGroups->assertJsonValidationErrors(['gallery_groups']);

        $tooManyItemsPayload = [];
        $tooManyItemFiles = [];
        for ($groupIndex = 0; $groupIndex < 6; $groupIndex++) {
            $items = [];
            $itemCount = $groupIndex === 0 ? 3 : 2;
            for ($itemIndex = 0; $itemIndex < $itemCount; $itemIndex++) {
                $itemKey = "item-{$groupIndex}-{$itemIndex}";
                $uploadKey = "upload_{$groupIndex}_{$itemIndex}";
                $items[] = [
                    'item_id' => $itemKey,
                    'upload' => $uploadKey,
                ];
                $tooManyItemFiles[$uploadKey] = UploadedFile::fake()->image("{$itemKey}.png", 900, 600);
            }
            $tooManyItemsPayload[] = [
                'group_id' => "group-{$groupIndex}",
                'subtitle' => "Group {$groupIndex}",
                'items' => $items,
            ];
        }

        $tooManyItems = $this->patchGallery($profile, $tooManyItemsPayload, $tooManyItemFiles);
        $tooManyItems->assertStatus(422);
        $tooManyItems->assertJsonValidationErrors(['gallery_groups']);
    }

    private function createPublicProfile(
        string $displayName,
        string $slug,
        string $profileType = 'venue',
    ): AccountProfile
    {
        return AccountProfile::query()->create([
            'account_id' => (string) $this->account->getKey(),
            'profile_type' => $profileType,
            'display_name' => $displayName,
            'slug' => $slug,
            'visibility' => 'public',
            'is_active' => true,
        ])->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $galleryGroups
     * @param  array<string, UploadedFile>  $files
     */
    private function patchGallery(
        AccountProfile $profile,
        array $galleryGroups,
        array $files = [],
    ): \Illuminate\Testing\TestResponse {
        return $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profiles/{$profile->getKey()}/gallery",
            array_merge([
                '_method' => 'PATCH',
                'gallery_groups' => json_encode($galleryGroups, JSON_THROW_ON_ERROR),
            ], $files),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedGalleryItem(AccountProfile $profile, string $itemId): ?array
    {
        foreach ($profile->gallery_groups ?? [] as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group['items'] ?? [] as $item) {
                if (is_array($item) && ($item['item_id'] ?? null) === $itemId) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function assertGalleryVariantStored(
        AccountProfile $profile,
        string $itemId,
        ?string $variant,
    ): string {
        $directory = 'tenants/'.$this->tenant->slug.'/account_profiles/'.$profile->getKey().'/';
        $baseName = basename('gallery-item-'.$itemId);

        foreach (Storage::disk('public')->allFiles($directory) as $path) {
            $fileName = basename($path);
            $matches = $variant === null
                ? preg_match('/^'.preg_quote($baseName, '/').'\.(jpg|jpeg|png|webp)$/', $fileName) === 1
                : preg_match('/^'.preg_quote($baseName, '/').'\.'.preg_quote($variant, '/').'\.(jpg|jpeg|png|webp)$/', $fileName) === 1;
            if ($matches) {
                Storage::disk('public')->assertExists($path);

                return $path;
            }
        }

        $this->fail("Gallery asset {$baseName} ({$variant}) was not stored.");
    }

    private function assertLongestEdgeAtMost(string $relativePath, int $maxEdge): void
    {
        $imageSize = @getimagesize(Storage::disk('public')->path($relativePath));
        $this->assertIsArray($imageSize);
        $this->assertLessThanOrEqual($maxEdge, max((int) $imageSize[0], (int) $imageSize[1]));
    }

    private function assertAspectRatioPreserved(string $relativePath, float $expectedRatio): void
    {
        $imageSize = @getimagesize(Storage::disk('public')->path($relativePath));
        $this->assertIsArray($imageSize);
        $ratio = (float) $imageSize[0] / max(1, (float) $imageSize[1]);
        $this->assertEqualsWithDelta($expectedRatio, $ratio, 0.02);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test'],
        );

        $service->initialize($payload);
    }

    private function getMultipartHeaders(): array
    {
        $headers = $this->getHeaders();
        unset($headers['Content-Type']);
        $headers['Accept'] = 'application/json';

        return $headers;
    }
}
