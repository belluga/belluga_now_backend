<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\StaticAsset;
use App\Models\Tenants\TenantProfileType;
use Belluga\Events\Models\Tenants\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;

class PublicWebMetadataShellTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private string $previousShellPath = '';

    private string $resolvedSiteName = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = $this->makeCanonicalTenantCurrent($this->tenant, allowSingleTenantContext: true);
        $this->resolvedSiteName = trim((string) $tenant->name);
        if ($this->resolvedSiteName === '') {
            $this->resolvedSiteName = trim((string) (Tenant::current()?->name ?? $this->resolvedSiteName));
        }
        $this->previousShellPath = (string) getenv('FLUTTER_WEB_SHELL_PATH');
        putenv('FLUTTER_WEB_SHELL_PATH='.realpath(__DIR__.'/../../Fixtures/PublicWeb/flutter_shell_index.html'));
    }

    protected function tearDown(): void
    {
        putenv('FLUTTER_WEB_SHELL_PATH='.$this->previousShellPath);
        parent::tearDown();
    }

    public function test_account_profile_public_route_injects_profile_metadata(): void
    {
        $tenantOrigin = rtrim($this->base_tenant_url, '/');
        $this->applyPublicWebMetadata([
            'default_title' => 'Fallback tenant title',
            'default_description' => 'Fallback tenant description.',
            'default_image' => 'https://tenant.example/media/fallback-cover.png',
        ]);
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'restaurant',
            'label' => 'Restaurante',
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $account = Account::create([
            'name' => 'Casa Marracini',
        ]);

        $profile = AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'restaurant',
            'display_name' => 'Casa Marracini',
            'slug' => 'casa-marracini',
            'visibility' => 'public',
            'bio' => 'Cozinha italiana perto do mar.',
            'content' => '<p>Massa fresca e carta de vinhos curada.</p>',
            'cover_url' => 'https://tenant.example/media/casa-cover.png',
            'avatar_url' => 'https://tenant.example/media/casa-avatar.png',
            'is_active' => true,
        ]);

        $response = $this->get("{$this->base_tenant_url}parceiro/{$profile->slug}");
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('<meta property="og:title" content="Casa Marracini | '.$this->resolvedSiteName.'">', false);
        $response->assertSee('<meta property="og:description" content="Massa fresca e carta de vinhos curada.">', false);
        $response->assertSee('<meta property="og:image" content="https://tenant.example/media/casa-cover.png">', false);
        $response->assertSee('<link rel="canonical" href="'.$tenantOrigin.'/parceiro/casa-marracini">', false);
    }

    public function test_event_public_route_injects_event_metadata_with_artist_cover_fallback(): void
    {
        $tenantOrigin = rtrim($this->base_tenant_url, '/');
        $this->applyPublicWebMetadata([
            'default_title' => 'Fallback tenant title',
            'default_description' => 'Fallback tenant description.',
            'default_image' => 'https://tenant.example/media/fallback-cover.png',
        ]);
        $event = Event::create([
            'slug' => 'festival-na-orla',
            'title' => 'Festival na Orla',
            'content' => '<p>Show ao pôr do sol em Guarapari.</p>',
            'date_time_start' => now()->subHour(),
            'date_time_end' => now()->addHours(2),
            'publication' => [
                'status' => 'published',
            ],
            'place_ref' => [
                'id' => 'place-1',
                'display_name' => 'Praia do Morro',
            ],
            'event_parties' => [
                [
                    'party_type' => 'artist',
                    'party_ref_id' => 'artist-1',
                    'permissions' => ['can_edit' => false],
                    'metadata' => [
                        'display_name' => 'Ananda Torres',
                        'slug' => 'ananda-torres',
                        'profile_type' => 'artist',
                        'cover_url' => 'https://tenant.example/media/ananda-cover.png',
                        'avatar_url' => 'https://tenant.example/media/ananda-avatar.png',
                    ],
                ],
            ],
        ]);

        $response = $this->get("{$this->base_tenant_url}agenda/evento/{$event->slug}");
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('<meta property="og:title" content="Festival na Orla | '.$this->resolvedSiteName.'">', false);
        $response->assertSee('<meta property="og:description" content="Show ao pôr do sol em Guarapari.">', false);
        $response->assertSee('<meta property="og:image" content="https://tenant.example/media/ananda-cover.png">', false);
        $response->assertSee('<link rel="canonical" href="'.$tenantOrigin.'/agenda/evento/festival-na-orla">', false);
    }

    public function test_unknown_public_route_uses_default_metadata_fallback(): void
    {
        $tenantOrigin = rtrim($this->base_tenant_url, '/');
        $this->applyPublicWebMetadata([
            'default_title' => 'Guarappari fallback',
            'default_description' => 'Descubra a agenda e os parceiros do tenant.',
            'default_image' => 'https://tenant.example/media/fallback-cover.png',
        ]);
        $response = $this->get("{$this->base_tenant_url}agenda/evento/nao-existe");
        $response->assertOk();
        $response->assertSee('<meta property="og:title" content="Guarappari fallback">', false);
        $response->assertSee('<meta property="og:description" content="Descubra a agenda e os parceiros do tenant.">', false);
        $response->assertSee('<meta property="og:image" content="https://tenant.example/media/fallback-cover.png">', false);
        $response->assertSee('<link rel="canonical" href="'.$tenantOrigin.'/agenda/evento/nao-existe">', false);
    }

    public function test_static_asset_public_route_injects_static_asset_metadata(): void
    {
        $tenantOrigin = rtrim($this->base_tenant_url, '/');
        $this->applyPublicWebMetadata([
            'default_title' => 'Fallback tenant title',
            'default_description' => 'Fallback tenant description.',
            'default_image' => 'https://tenant.example/media/fallback-cover.png',
        ]);
        $asset = StaticAsset::create([
            'profile_type' => 'beach',
            'display_name' => 'Praia das Virtudes',
            'slug' => 'praia-das-virtudes',
            'bio' => 'Faixa de areia com vista aberta para o mar.',
            'content' => '<p>Quiosques, píer e um pôr do sol forte no fim da tarde.</p>',
            'cover_url' => 'https://tenant.example/media/praia-cover.png',
            'is_active' => true,
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.5001, -20.6701],
            ],
        ]);

        $response = $this->get("{$this->base_tenant_url}static/{$asset->slug}");
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('<meta property="og:title" content="Praia das Virtudes | '.$this->resolvedSiteName.'">', false);
        $response->assertSee('<meta property="og:description" content="Quiosques, píer e um pôr do sol forte no fim da tarde.">', false);
        $response->assertSee('<meta property="og:image" content="https://tenant.example/media/praia-cover.png">', false);
        $response->assertSee('<link rel="canonical" href="'.$tenantOrigin.'/static/praia-das-virtudes">', false);
    }

    public function test_home_route_uses_branding_fallback_metadata(): void
    {
        $tenantOrigin = rtrim($this->base_tenant_url, '/');
        $this->applyPublicWebMetadata([
            'default_title' => 'Guarappari Home',
            'default_description' => 'Fallback da home tenant-aware.',
            'default_image' => 'https://tenant.example/media/home-fallback.png',
        ]);

        $response = $this->get($this->base_tenant_url);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('<meta property="og:title" content="Guarappari Home">', false);
        $response->assertSee('<meta property="og:description" content="Fallback da home tenant-aware.">', false);
        $response->assertSee('<meta property="og:image" content="https://tenant.example/media/home-fallback.png">', false);
        $response->assertSee('<meta property="og:image:secure_url" content="https://tenant.example/media/home-fallback.png">', false);
        $response->assertSee('<meta property="og:image:type" content="image/png">', false);
        $response->assertSee('<meta property="og:image:alt" content="Guarappari Home">', false);
        $response->assertSee('<link rel="canonical" href="'.$tenantOrigin.'/">', false);
    }

    public function test_home_route_rewrites_internal_branding_fallback_image_to_current_tenant_host(): void
    {
        $tenantOrigin = rtrim($this->base_tenant_url, '/');
        $tenant = $this->currentTenant();
        $legacyPath = "tenants/{$tenant->slug}/public-web/default-image.jpg";
        $legacyFile = UploadedFile::fake()->image('default-image.jpg', 1200, 630);
        Storage::disk('public')->put($legacyPath, file_get_contents($legacyFile->getRealPath()));
        $this->applyPublicWebMetadata([
            'default_title' => 'Guarappari Home',
            'default_description' => 'Fallback da home tenant-aware.',
            'default_image' => "https://belluga.space/storage/tenants/{$tenant->slug}/public-web/default-image.jpg",
        ]);

        $response = $this->get($this->base_tenant_url);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee(
            '<meta property="og:image" content="'.$tenantOrigin.'/api/v1/media/branding-public-web/'.$tenant->_id.'/default_image?v=',
            false
        );
        $response->assertSee('<meta property="og:image:type" content="image/jpeg">', false);
        $response->assertSee('<meta property="og:image:width" content="1200">', false);
        $response->assertSee('<meta property="og:image:height" content="630">', false);
        $response->assertSee('<meta property="og:image:alt" content="Guarappari Home">', false);
        $response->assertDontSee(
            '<meta property="og:image" content="https://belluga.space/storage/tenants/guarappari/public-web/default-image.jpg">',
            false
        );
    }

    public function test_generic_tenant_public_route_uses_requested_url_with_branding_fallback(): void
    {
        $tenantOrigin = rtrim($this->base_tenant_url, '/');
        $this->applyPublicWebMetadata([
            'default_title' => 'Guarappari Mapa',
            'default_description' => 'Fallback para rotas publicas sem entidade.',
            'default_image' => 'https://tenant.example/media/mapa-fallback.png',
        ]);

        $response = $this->get("{$this->base_tenant_url}mapa?origem=home");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('<meta property="og:title" content="Guarappari Mapa">', false);
        $response->assertSee('<meta property="og:description" content="Fallback para rotas publicas sem entidade.">', false);
        $response->assertSee('<meta property="og:image" content="https://tenant.example/media/mapa-fallback.png">', false);
        $response->assertSee('<link rel="canonical" href="'.$tenantOrigin.'/mapa?origem=home">', false);
    }

    public function test_admin_path_is_not_captured_by_public_shell_fallback(): void
    {
        $response = $this->get("{$this->base_tenant_url}admin/settings/visual-identity");

        $response->assertNotFound();
    }

    private function currentTenant(): Tenant
    {
        return $this->resolveCanonicalTenant($this->tenant, allowSingleTenantContext: true);
    }

    /**
     * @param  array<string, string>  $metadata
     */
    private function applyPublicWebMetadata(array $metadata): void
    {
        $tenant = $this->currentTenant();
        $tenant->branding_data = array_replace_recursive(
            $tenant->branding_data ?? [],
            [
                'public_web_metadata' => $metadata,
            ]
        );
        $tenant->save();
    }
}
