<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Http\Api\v1\Requests\AccountOnboardingStoreRequest;
use App\Http\Api\v1\Requests\AccountProfileStoreRequest;
use App\Http\Api\v1\Requests\AccountProfileUpdateRequest;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use App\Models\Tenants\TenantProfileType;
use App\Support\RichText\RichTextReadCanonicalizer;
use App\Support\RichText\SafeRichTextHtmlSanitizer;
use Illuminate\Support\Facades\Validator;
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
            ],
        ]);

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
        ]);
    }

    public function test_onboarding_sanitizes_bio_rich_text_before_persistence(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Rich Text Profile '.Str::random(6),
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'bio' => "Linha 1 🎉\nLinha 2",
            ],
            $this->getHeaders()
        );

        $response->assertCreated();
        $profileId = (string) $response->json('data.account_profile.id');

        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $stored = AccountProfile::query()->findOrFail($profileId);

        $expectedBio = '<p>Linha 1 🎉<br />Linha 2</p>';
        $this->assertSame($expectedBio, $response->json('data.account_profile.bio'));
        $this->assertSame($expectedBio, $stored->bio);
        $this->assertArrayNotHasKey('content', (array) $response->json('data.account_profile'));
        $this->assertNull($stored->getAttribute('content'));
    }

    public function test_onboarding_rejects_an_explicit_content_key(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Content Rejection Onboarding '.Str::random(6),
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'bio' => '<p>Bio válida</p>',
                'content' => '<p>Legacy content must be rejected</p>',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey('content', (array) $response->json('errors'));
    }

    public function test_store_request_and_legacy_endpoint_reject_explicit_content_without_persistence(): void
    {
        $beforeCount = AccountProfile::query()->count();
        $payload = [
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Legacy Content Rejection',
            'bio' => '<p>Bio válida</p>',
            'content' => '<p>Legacy content must be rejected</p>',
        ];

        $validator = Validator::make(
            $payload,
            (new AccountProfileStoreRequest)->rules()
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content', $validator->errors()->toArray());

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles",
            $payload,
            $this->getHeaders()
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'tenant_admin_onboarding_required');

        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $this->assertSame($beforeCount, AccountProfile::query()->count());
    }

    public function test_all_write_requests_reject_the_content_key_even_when_its_value_is_empty(): void
    {
        $requests = [
            new AccountOnboardingStoreRequest,
            new AccountProfileStoreRequest,
            new AccountProfileUpdateRequest,
        ];
        $explicitValues = [null, '', []];

        foreach ($requests as $request) {
            foreach ($explicitValues as $content) {
                $validator = Validator::make(
                    ['content' => $content],
                    $request->rules(),
                );

                $this->assertTrue($validator->fails());
                $this->assertArrayHasKey('content', $validator->errors()->toArray());
            }
        }
    }

    public function test_update_rejects_an_explicit_content_key(): void
    {
        $profile = $this->createProfile([
            'bio' => '<p>Bio antiga</p>',
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'bio' => '<p>Bio nova</p>',
                'content' => '<p>Legacy content must be rejected</p>',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey('content', (array) $response->json('errors'));

        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $stored = $profile->fresh();
        $this->assertSame('<p>Bio antiga</p>', (string) $stored->bio);
    }

    public function test_update_sanitizes_fields_independently_and_strips_media_only_content(): void
    {
        $profile = $this->createProfile([
            'bio' => '<p>Bio antiga</p>',
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'bio' => '<p><img src="https://example.com/banner.png" alt="Banner"></p><p><br /></p>',
            ],
            $this->getHeaders()
        );

        $response->assertOk();

        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $stored = $profile->fresh();

        $this->assertSame('', $response->json('data.bio'));
        $this->assertSame('', (string) $stored->bio);
    }

    public function test_update_preserves_heading_boundaries_across_adjacent_rich_text_blocks(): void
    {
        $profile = $this->createProfile();
        $bio = '<h2>Bio Heading 🎉</h2>'
            .'<p><strong>Bold bio</strong><br />Second bio line</p>'
            .'<blockquote>Bio quote</blockquote>'
            .'<ul><li>Bio bullet</li></ul>';
        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'bio' => $bio,
            ],
            $this->getHeaders()
        );

        $response->assertOk();

        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $stored = $profile->fresh();

        $this->assertSame($bio, $response->json('data.bio'));
        $this->assertSame($bio, $stored->bio);
        $this->assertStringNotContainsString('<h2>Bio Heading 🎉<p>', (string) $stored->bio);
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
            ],
            $this->getHeaders()
        );

        $accepted->assertOk();
        $this->assertSame(self::RICH_TEXT_MAX_BYTES, strlen((string) $accepted->json('data.bio')));

        $bioRejected = $this->patchJson(
            $profileUrl,
            [
                'bio' => $overLimit,
            ],
            $this->getHeaders()
        );

        $bioRejected->assertStatus(422);
        $bioErrors = (array) $bioRejected->json('errors');
        $this->assertArrayHasKey('bio', $bioErrors);
    }

    public function test_raw_payload_larger_than_limit_is_rejected_before_sanitization(): void
    {
        $raw = str_repeat(
            '<img src="https://example.com/banner.png" alt="Banner">',
            3000
        ).'<p>Ok 🎉</p>';

        $storeValidator = Validator::make(
            [
                'account_id' => (string) $this->account->_id,
                'profile_type' => 'personal',
                'display_name' => 'Raw Oversized Store '.Str::random(6),
                'bio' => $raw,
            ],
            (new AccountProfileStoreRequest)->rules()
        );

        $this->assertTrue($storeValidator->fails());
        $this->assertArrayHasKey('bio', $storeValidator->errors()->toArray());

        $profile = $this->createProfile();
        $updateResponse = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'bio' => $raw,
            ],
            $this->getHeaders()
        );

        $updateResponse->assertStatus(422);
        $this->assertArrayHasKey('bio', (array) $updateResponse->json('errors'));

        $onboardingResponse = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Raw Oversized Onboarding '.Str::random(6),
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'bio' => $raw,
            ],
            $this->getHeaders()
        );

        $onboardingResponse->assertStatus(422);
        $this->assertArrayHasKey('bio', (array) $onboardingResponse->json('errors'));
    }

    public function test_account_profile_rich_text_sanitizer_uses_neutral_shared_support(): void
    {
        $source = (string) file_get_contents(app_path('Application/AccountProfiles/AccountProfileRichTextSanitizer.php'));
        $wrapper = (string) file_get_contents(app_path('Support/RichText/SafeRichTextHtmlSanitizer.php'));

        $this->assertStringContainsString('SafeRichTextHtmlSanitizer', $source);
        $this->assertStringNotContainsString('Belluga\\Events\\Support\\EventContentHtmlSanitizer', $source);
        $this->assertStringContainsString('Belluga\\RichText\\SafeRichTextHtmlSanitizer', $wrapper);
    }

    public function test_shared_rich_text_sanitizer_unwraps_unsupported_containers_and_removes_dangerous_content(): void
    {
        $sanitized = SafeRichTextHtmlSanitizer::sanitize(
            '<div>Antes <iframe>texto interno</iframe> <u>under</u> after</div>'
            .'<script>alert(1)</script><style>.x{}</style>'
        );

        $this->assertSame('<p>Antes texto interno under after</p>', $sanitized);
        $this->assertStringNotContainsString('<iframe', $sanitized);
        $this->assertStringNotContainsString('<u>', $sanitized);
        $this->assertStringNotContainsString('alert', $sanitized);
        $this->assertStringNotContainsString('<style', $sanitized);
    }

    public function test_shared_rich_text_sanitizer_matches_cross_stack_fixtures(): void
    {
        $fixtures = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/shared_rich_text/safe_rich_html_fixtures.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($fixtures as $fixture) {
            $input = $this->fixtureText($fixture, 'input', 'input_repeat');
            $expected = $this->fixtureText($fixture, 'expected', 'expected_repeat');
            $this->assertSame(
                $expected,
                SafeRichTextHtmlSanitizer::sanitize($input),
                (string) $fixture['name']
            );
            if (isset($fixture['explicit_https_expected']) || isset($fixture['explicit_https_expected_repeat'])) {
                $explicitHttpsExpected = $this->fixtureText(
                    $fixture,
                    'explicit_https_expected',
                    'explicit_https_expected_repeat'
                );
                $canonical = SafeRichTextHtmlSanitizer::sanitize($input, true);
                $this->assertSame(
                    $explicitHttpsExpected,
                    $canonical,
                    (string) $fixture['name']
                );
                $this->assertSame(
                    $explicitHttpsExpected,
                    SafeRichTextHtmlSanitizer::sanitize($canonical, true),
                    (string) $fixture['name'].' must be idempotent'
                );
            }
        }
    }

    public function test_unterminated_anchor_preflight_grows_proportionately_near_100_kb(): void
    {
        $smaller = $this->unterminatedAnchorSequence(800);
        $nearLimit = $this->unterminatedAnchorSequence(2450);

        $this->assertLessThanOrEqual(self::RICH_TEXT_MAX_BYTES, strlen($nearLimit));
        $this->assertGreaterThan(98000, strlen($nearLimit));
        $this->assertSame(
            '<p>'.str_repeat('x', 2450).'</p>',
            SafeRichTextHtmlSanitizer::sanitize($nearLimit, true)
        );

        // A 3.06x input increase has a deliberately loose 6x budget. The former
        // repeated forward matching grows quadratically and exceeds this ratio.
        $smallerNanoseconds = $this->bestSanitizationNanoseconds($smaller);
        $nearLimitNanoseconds = $this->bestSanitizationNanoseconds($nearLimit);
        $this->assertLessThanOrEqual($smallerNanoseconds * 6, $nearLimitNanoseconds);
        $this->assertLessThan(2_000_000_000, $nearLimitNanoseconds);
    }

    public function test_quoted_unterminated_anchor_candidates_after_valid_prefix_stay_linear(): void
    {
        $smaller = $this->unterminatedQuotedAnchorCandidates(2500);
        $nearLimit = $this->unterminatedQuotedAnchorCandidates(10000);

        $this->assertLessThanOrEqual(self::RICH_TEXT_MAX_BYTES, strlen($nearLimit));
        $this->assertGreaterThan(90000, strlen($nearLimit));
        $this->assertSame(
            '<p><a href="https://example.test/valid">valid</a></p>',
            SafeRichTextHtmlSanitizer::sanitize($nearLimit, true)
        );

        // The 4x input increase has a loose 8x budget. A scanner that restarts
        // at every nested `<a` candidate grows quadratically and exceeds it.
        $smallerNanoseconds = $this->bestSanitizationNanoseconds($smaller);
        $nearLimitNanoseconds = $this->bestSanitizationNanoseconds($nearLimit);
        $this->assertLessThanOrEqual($smallerNanoseconds * 8, $nearLimitNanoseconds);
        $this->assertLessThan(2_000_000_000, $nearLimitNanoseconds);
    }

    public function test_many_valid_anchors_grow_proportionately_near_100_kb(): void
    {
        $smaller = $this->manyValidAnchors(550);
        $nearLimit = $this->manyValidAnchors(2200);

        $this->assertLessThanOrEqual(self::RICH_TEXT_MAX_BYTES, strlen($nearLimit));
        $this->assertGreaterThan(90000, strlen($nearLimit));
        $this->assertSame($nearLimit, SafeRichTextHtmlSanitizer::sanitize($nearLimit, true));

        $smallerNanoseconds = $this->bestSanitizationNanoseconds($smaller);
        $nearLimitNanoseconds = $this->bestSanitizationNanoseconds($nearLimit);
        $this->assertLessThanOrEqual($smallerNanoseconds * 8, $nearLimitNanoseconds);
        $this->assertLessThan(2_000_000_000, $nearLimitNanoseconds);
    }

    public function test_shared_https_anchor_fixture_preserves_explicit_link_and_rejects_unsafe_values(): void
    {
        $this->assertSame(
            '<p><a href="https://example.test/docs?one=1&amp;two=2">documentação</a></p>',
            SafeRichTextHtmlSanitizer::sanitize(
                '<p><a href="HTTPS://example.test/docs?one=1&amp;two=2">documentação</a></p>',
                true
            )
        );
        $this->assertSame(
            '<p>unsafe</p>',
            SafeRichTextHtmlSanitizer::sanitize(
                '<p><a href="javascript:alert(1)">unsafe</a></p>',
                true
            )
        );
    }

    public function test_read_canonicalizer_bounds_legacy_values_and_memoizes_safe_anchors(): void
    {
        $calls = [];
        $canonicalizer = new RichTextReadCanonicalizer(
            static function (string $value, bool $allowExplicitHttpsLinks) use (&$calls): string {
                $calls[] = [$value, $allowExplicitHttpsLinks];

                return SafeRichTextHtmlSanitizer::sanitize($value, $allowExplicitHttpsLinks);
            }
        );
        $safe = '<p><a href="https://example.test">safe</a></p>';
        $identity = ['account_profile', 'profile-1', 'bio'];
        $this->assertSame($safe, $canonicalizer->canonicalize($safe, true, ...$identity));
        $this->assertSame($safe, $canonicalizer->canonicalize($safe, true, ...$identity));
        $this->assertSame('<p>safe</p>', $canonicalizer->canonicalize($safe, false, ...$identity));
        $this->assertSame($safe, $canonicalizer->canonicalize($safe, true, 'account_profile', 'profile-2', 'bio'));
        $this->assertSame($safe, $canonicalizer->canonicalize($safe, true, 'event', 'profile-1', 'bio'));
        $this->assertSame('<p>distinct</p>', $canonicalizer->canonicalize('<p>distinct</p>', true, ...$identity));
        $this->assertSame(
            [
                [$safe, true],
                [$safe, false],
                [$safe, true],
                [$safe, true],
                ['<p>distinct</p>', true],
            ],
            $calls
        );
        $this->assertSame('', $canonicalizer->canonicalize(str_repeat('a', RichTextReadCanonicalizer::RICH_TEXT_READ_MAX_BYTES + 1), true, ...$identity));
        $this->assertSame('', $canonicalizer->canonicalize(['invalid'], true, ...$identity));
    }

    public function test_read_canonicalizer_has_request_scoped_lifecycle(): void
    {
        $first = $this->app->make(RichTextReadCanonicalizer::class);
        $this->assertSame($first, $this->app->make(RichTextReadCanonicalizer::class));

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, $this->app->make(RichTextReadCanonicalizer::class));
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

    /** @param array<string, mixed> $fixture */
    private function fixtureText(array $fixture, string $directKey, string $repeatKey): string
    {
        if (isset($fixture[$directKey]) && is_string($fixture[$directKey])) {
            return $fixture[$directKey];
        }

        /** @var array{prefix:string,fragment:string,count:int,suffix:string} $repeat */
        $repeat = $fixture[$repeatKey];

        return $repeat['prefix'].str_repeat($repeat['fragment'], $repeat['count']).$repeat['suffix'];
    }

    private function unterminatedAnchorSequence(int $count): string
    {
        return '<p>'.str_repeat('<a href="https://example.test/unclosed">x', $count).'</p>';
    }

    private function unterminatedQuotedAnchorCandidates(int $count): string
    {
        return '<p><a href="https://example.test/valid">valid</a></p>'.str_repeat('<a href="', $count);
    }

    private function manyValidAnchors(int $count): string
    {
        return '<p>'.str_repeat('<a href="https://example.test/valid">x</a>', $count).'</p>';
    }

    private function bestSanitizationNanoseconds(string $input): int
    {
        $best = PHP_INT_MAX;
        for ($sample = 0; $sample < 3; $sample++) {
            $started = hrtime(true);
            for ($iteration = 0; $iteration < 3; $iteration++) {
                SafeRichTextHtmlSanitizer::sanitize($input, true);
            }
            $best = min($best, hrtime(true) - $started);
        }

        return $best;
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
