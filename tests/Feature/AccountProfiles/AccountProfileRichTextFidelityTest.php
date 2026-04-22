<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Str;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountProfileRichTextFidelityTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private const RICH_TEXT_MAX_BYTES = 102400;

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

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        AccountProfile::query()->delete();
        TaxonomyTerm::query()->delete();
        Taxonomy::query()->delete();
        TenantProfileType::query()->delete();

        TenantProfileType::create([
            'type' => 'personal',
            'label' => 'Personal',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => false,
                'is_poi_enabled' => false,
                'has_bio' => true,
                'has_content' => true,
            ],
        ]);

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
        ]);
    }

    public function test_onboarding_sanitizes_bio_and_content_rich_text_before_persistence(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Rich Text Profile '.Str::random(6),
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'bio' => "Linha 1 🎉\nLinha 2",
                'content' => '<h2>Perfil</h2><p><strong>Forte</strong> <em>italico</em> <s>corte</s> <u>under</u> <a href="https://example.com">link</a> 🎉</p><blockquote>Quote</blockquote><ul><li>Um</li></ul><ol><li>Dois</li></ol><script>alert("x")</script><style>.x{}</style><p><img src="https://example.com/banner.png" alt="Banner"></p>',
            ],
            $this->getHeaders()
        );

        $response->assertCreated();
        $profileId = (string) $response->json('data.account_profile.id');
        $stored = AccountProfile::query()->findOrFail($profileId);

        $expectedBio = '<p>Linha 1 🎉<br />Linha 2</p>';
        $this->assertSame($expectedBio, $response->json('data.account_profile.bio'));
        $this->assertSame($expectedBio, $stored->bio);

        $content = (string) $response->json('data.account_profile.content');
        $this->assertSame($content, $stored->content);
        $this->assertStringContainsString('<h2>Perfil', $content);
        $this->assertStringContainsString('<strong>Forte</strong>', $content);
        $this->assertStringContainsString('<em>italico</em>', $content);
        $this->assertStringContainsString('<s>corte</s>', $content);
        $this->assertStringContainsString('under link 🎉', $content);
        $this->assertStringContainsString('<blockquote>Quote</blockquote>', $content);
        $this->assertStringContainsString('<ul><li>Um</li></ul>', $content);
        $this->assertStringContainsString('<ol><li>Dois</li></ol>', $content);
        $this->assertStringNotContainsString('<u>', $content);
        $this->assertStringNotContainsString('</u>', $content);
        $this->assertStringNotContainsString('<a', $content);
        $this->assertStringNotContainsString('href=', $content);
        $this->assertStringNotContainsString('<img', $content);
        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringNotContainsString('alert', $content);
        $this->assertStringNotContainsString('<style', $content);
    }

    public function test_update_sanitizes_fields_independently_and_strips_media_only_content(): void
    {
        $profile = $this->createProfile([
            'bio' => '<p>Bio antiga</p>',
            'content' => '<p>Conteudo antigo</p>',
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'bio' => '<p><img src="https://example.com/banner.png" alt="Banner"></p><p><br /></p>',
                'content' => "Conteúdo 1 🎉\nConteúdo 2",
            ],
            $this->getHeaders()
        );

        $response->assertOk();
        $stored = $profile->fresh();

        $this->assertSame('', $response->json('data.bio'));
        $this->assertSame('', (string) $stored->bio);

        $expectedContent = '<p>Conteúdo 1 🎉<br />Conteúdo 2</p>';
        $this->assertSame($expectedContent, $response->json('data.content'));
        $this->assertSame($expectedContent, $stored->content);
    }

    public function test_update_preserves_heading_boundaries_across_adjacent_rich_text_blocks(): void
    {
        $profile = $this->createProfile();
        $bio = '<h2>Bio Heading 🎉</h2>'
            .'<p><strong>Bold bio</strong><br />Second bio line</p>'
            .'<blockquote>Bio quote</blockquote>'
            .'<ul><li>Bio bullet</li></ul>';
        $content = '<h3>Content Heading</h3>'
            .'<p><em>Italic content</em> and <s>strike content</s> 😄</p>'
            .'<ol><li>Content ordered</li></ol>';

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'bio' => $bio,
                'content' => $content,
            ],
            $this->getHeaders()
        );

        $response->assertOk();
        $stored = $profile->fresh();

        $this->assertSame($bio, $response->json('data.bio'));
        $this->assertSame($bio, $stored->bio);
        $this->assertStringNotContainsString('<h2>Bio Heading 🎉<p>', (string) $stored->bio);

        $this->assertSame($content, $response->json('data.content'));
        $this->assertSame($content, $stored->content);
        $this->assertStringNotContainsString('<h3>Content Heading<p>', (string) $stored->content);
    }

    public function test_rich_text_limit_is_enforced_after_sanitization_per_field(): void
    {
        $profile = $this->createProfile();
        $profileUrl = "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id;
        $exact = $this->htmlParagraphOfSanitizedByteLength(self::RICH_TEXT_MAX_BYTES);
        $overLimit = $this->htmlParagraphOfSanitizedByteLength(self::RICH_TEXT_MAX_BYTES + 1);

        $accepted = $this->patchJson(
            $profileUrl,
            [
                'bio' => $exact,
                'content' => $exact,
            ],
            $this->getHeaders()
        );

        $accepted->assertOk();
        $this->assertSame(self::RICH_TEXT_MAX_BYTES, strlen((string) $accepted->json('data.bio')));
        $this->assertSame(self::RICH_TEXT_MAX_BYTES, strlen((string) $accepted->json('data.content')));

        $bioRejected = $this->patchJson(
            $profileUrl,
            [
                'bio' => $overLimit,
                'content' => $exact,
            ],
            $this->getHeaders()
        );

        $bioRejected->assertStatus(422);
        $bioErrors = (array) $bioRejected->json('errors');
        $this->assertArrayHasKey('bio', $bioErrors);
        $this->assertArrayNotHasKey('content', $bioErrors);

        $contentRejected = $this->patchJson(
            $profileUrl,
            [
                'bio' => $exact,
                'content' => $overLimit,
            ],
            $this->getHeaders()
        );

        $contentRejected->assertStatus(422);
        $contentErrors = (array) $contentRejected->json('errors');
        $this->assertArrayNotHasKey('bio', $contentErrors);
        $this->assertArrayHasKey('content', $contentErrors);
    }

    public function test_raw_payload_larger_than_limit_is_accepted_when_sanitized_under_limit(): void
    {
        $profile = $this->createProfile();
        $raw = str_repeat(
            '<img src="https://example.com/banner.png" alt="Banner">',
            3000
        ).'<p>Ok 🎉</p>';

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'bio' => $raw,
                'content' => $raw,
            ],
            $this->getHeaders()
        );

        $response->assertOk();
        $this->assertSame('<p>Ok 🎉</p>', $response->json('data.bio'));
        $this->assertSame('<p>Ok 🎉</p>', $response->json('data.content'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createProfile(array $attributes = []): AccountProfile
    {
        /** @var AccountProfile $profile */
        $profile = AccountProfile::create(array_merge([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Rich Text Stored Profile '.Str::random(6),
            'is_active' => true,
        ], $attributes))->fresh();

        return $profile;
    }

    private function htmlParagraphOfSanitizedByteLength(int $bytes): string
    {
        $overhead = strlen('<p></p>');
        $this->assertGreaterThan($overhead, $bytes);

        return '<p>'.str_repeat('a', $bytes - $overhead).'</p>';
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
            tenantDomains: ['tenant-zeta.test']
        );

        $service->initialize($payload);
    }
}
