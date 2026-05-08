<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Landlord\ApiAbuseSignal;
use App\Models\Landlord\ApiAbuseSignalAggregate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiSecurityHardeningMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/api/v1/_security_test/l3', static fn () => response()->json(['ok' => true]));
        Route::post('/api/v1/_security_test/l2', static fn () => response()->json(['ok' => true]));
        Route::post('/admin/api/v1/{tenant_slug}/_security_test/tenant-level', static fn () => response()->json(['ok' => true]));
        Route::post('/api/v1/_security_test/checkout/confirm', static fn () => response()->json(['ok' => true]));
        Route::post('/api/v1/_security_test/events/{event}/occurrences/{occurrence}/admission', static fn () => response()->json(['ok' => true]));
        Route::patch('/api/v1/_security_test/settings/values/map_ui', static fn () => response()->json(['ok' => true]));
        Route::post('/api/v1/_security_test/events/admin', static fn () => response()->json(['ok' => true]));
        Route::post('/api/v1/_security_test/account_onboardings', static fn () => response()->json(['ok' => true]));
        Route::post('/api/v1/_security_test/domain-alpha', static fn () => response()->json(['ok' => true]));
        Route::post('/api/v1/_security_test/domain-beta', static fn () => response()->json(['ok' => true]));
        Route::post('/api/v1/_security_test/public-login', static fn () => response()->json(['ok' => true]));

        $overrides = (array) config('api_security.route_overrides', []);
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/l3$#',
            'domain' => 'security_test_l3',
            'methods' => ['POST'],
            'level' => 'L3',
            'require_idempotency' => true,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/l2$#',
            'domain' => 'security_test_l2',
            'methods' => ['POST'],
            'level' => 'L2',
            'require_idempotency' => false,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/checkout/confirm$#',
            'domain' => 'security_test_checkout',
            'methods' => ['POST'],
            'level' => 'L3',
            'require_idempotency' => true,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/events/[^/]+/occurrences/[^/]+/admission$#',
            'domain' => 'security_test_admission',
            'methods' => ['POST'],
            'level' => 'L3',
            'require_idempotency' => true,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/settings/values/[^/]+$#',
            'domain' => 'security_test_settings',
            'methods' => ['PATCH'],
            'level' => 'L2',
            'require_idempotency' => false,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/events/admin$#',
            'domain' => 'security_test_events_admin',
            'methods' => ['POST'],
            'level' => 'L2',
            'require_idempotency' => false,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/account_onboardings$#',
            'domain' => 'security_test_account_onboardings',
            'methods' => ['POST'],
            'level' => 'L2',
            'require_idempotency' => false,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/domain-alpha$#',
            'domain' => 'security_test_domain_alpha',
            'methods' => ['POST'],
            'level' => 'L2',
            'require_idempotency' => false,
            'requests_per_minute' => 1,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/domain-beta$#',
            'domain' => 'security_test_domain_beta',
            'methods' => ['POST'],
            'level' => 'L2',
            'require_idempotency' => false,
            'requests_per_minute' => 3,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/public-login$#',
            'domain' => 'security_test_public_login',
            'methods' => ['POST'],
            'level' => 'L2',
            'require_idempotency' => false,
            'requests_per_minute' => 50,
            'subject_input' => 'email',
            'subject_kind' => 'email',
            'subject_requests_per_minute' => 1,
            'fail_closed_on_backend_error' => true,
        ];
        config()->set('api_security.route_overrides', $overrides);

        config()->set('api_security.tenant_overrides.enabled', true);
        config()->set('api_security.tenant_overrides.tenants', [
            'tenant-l1' => ['level' => 'L1'],
            'tenant-l3' => ['level' => 'L3', 'require_idempotency' => true],
        ]);

        config()->set('api_security.minimum_level', 'L1');
        config()->set('api_security.lifecycle.warn_after', 1);
        config()->set('api_security.lifecycle.challenge_after', 2);
        config()->set('api_security.lifecycle.soft_block_after', 4);
        config()->set('api_security.lifecycle.hard_block_after', 8);
        config()->set('api_security.lifecycle.challenge_seconds', 30);
        config()->set('api_security.lifecycle.soft_block_seconds', 45);
        config()->set('api_security.lifecycle.hard_block_seconds', 90);
        config()->set('api_security.cloudflare.enforce_origin_lock', false);
        config()->set('api_security.cloudflare.require_trusted_proxy_for_forwarded_headers', true);
        config()->set('api_security.observe_mode', false);
        config()->set('api_security.levels.L3.requests_per_minute', 9999);
        config()->set('api_security.levels.L2.requests_per_minute', 9999);

        $this->setTrustedProxiesEnv('');
        Cache::flush();
    }

    public function test_l3_requires_idempotency_key(): void
    {
        $response = $this->postJson('/api/v1/_security_test/l3', ['payload' => 'alpha']);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'idempotency_missing');
        $response->assertHeader('X-Api-Security-Level', 'L3');
        $response->assertHeader('X-Correlation-Id');
        $response->assertHeader('X-Api-Security-Observe-Mode', 'false');
    }

    public function test_observe_mode_logs_without_blocking_l3_missing_idempotency(): void
    {
        config()->set('api_security.observe_mode', true);

        $response = $this->postJson('/api/v1/_security_test/l3', ['payload' => 'alpha']);
        $response->assertOk()->assertJsonPath('ok', true);
        $response->assertHeader('X-Api-Security-Observe-Mode', 'true');
        $response->assertHeader('X-Api-Security-Level', 'L3');
    }

    public function test_l3_replays_same_payload_with_cached_response(): void
    {
        $headers = ['Idempotency-Key' => 'abc12345-security-test'];

        $first = $this->postJson('/api/v1/_security_test/l3', ['payload' => 'alpha'], $headers);
        $first->assertOk()->assertJsonPath('ok', true);

        $second = $this->postJson('/api/v1/_security_test/l3', ['payload' => 'alpha'], $headers);
        $second->assertOk()->assertJsonPath('ok', true);
        $second->assertHeader('X-Idempotency-Replayed', 'true');
    }

    public function test_l3_rejects_same_key_with_different_payload(): void
    {
        $headers = ['Idempotency-Key' => 'abc12345-security-test-mismatch'];

        $this->postJson('/api/v1/_security_test/l3', ['payload' => 'alpha'], $headers)
            ->assertOk();

        $second = $this->postJson('/api/v1/_security_test/l3', ['payload' => 'beta'], $headers);
        $second->assertStatus(409);
        $second->assertJsonPath('code', 'idempotency_replayed');
    }

    public function test_origin_lock_rejects_non_cloudflare_requests_when_enabled(): void
    {
        config()->set('api_security.cloudflare.enforce_origin_lock', true);
        config()->set('api_security.cloudflare.require_trusted_proxy_for_forwarded_headers', false);

        $blocked = $this->postJson('/api/v1/_security_test/l2', ['payload' => 'alpha']);
        $blocked->assertStatus(403);
        $blocked->assertJsonPath('code', 'origin_access_denied');

        $allowed = $this
            ->withHeaders(['CF-Ray' => 'abc-xyz'])
            ->postJson('/api/v1/_security_test/l2', ['payload' => 'alpha']);
        $allowed->assertOk()->assertJsonPath('ok', true);
        $allowed->assertHeader('X-CF-Ray-Id', 'abc-xyz');
    }

    public function test_origin_lock_rejects_cloudflare_headers_when_proxy_is_not_trusted(): void
    {
        config()->set('api_security.cloudflare.enforce_origin_lock', true);
        config()->set('api_security.cloudflare.require_trusted_proxy_for_forwarded_headers', true);

        $this->setTrustedProxiesEnv('');

        $response = $this
            ->withHeaders([
                'CF-Ray' => 'cf-ray-untrusted',
                'CF-Connecting-IP' => '198.51.100.10',
            ])
            ->postJson('/api/v1/_security_test/l2', ['payload' => 'alpha']);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'spoofed_client_ip_header');
    }

    public function test_origin_lock_allows_cloudflare_headers_from_trusted_proxy(): void
    {
        config()->set('api_security.cloudflare.enforce_origin_lock', true);
        config()->set('api_security.cloudflare.require_trusted_proxy_for_forwarded_headers', true);

        $this->setTrustedProxiesEnv('127.0.0.1');

        $response = $this
            ->withHeaders([
                'CF-Ray' => 'cf-ray-trusted',
                'CF-Connecting-IP' => '198.51.100.10',
            ])
            ->postJson('/api/v1/_security_test/l2', ['payload' => 'alpha']);

        $response->assertOk()->assertJsonPath('ok', true);
        $response->assertHeader('X-CF-Ray-Id', 'cf-ray-trusted');
    }

    public function test_spoofed_client_ip_header_is_rejected_when_proxy_is_untrusted(): void
    {
        $response = $this
            ->withHeaders(['X-Forwarded-For' => '1.2.3.4'])
            ->postJson('/api/v1/_security_test/l2', ['payload' => 'alpha']);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'spoofed_client_ip_header');
    }

    public function test_lifecycle_warn_header_is_exposed_after_first_violation(): void
    {
        $this->json('post', '/api/v1/_security_test/l2', ['payload' => 'first'], [
            'X-Forwarded-For' => '1.2.3.4',
        ])->assertStatus(403);

        $response = $this->postJson('/api/v1/_security_test/l2', ['payload' => 'second']);
        $response->assertOk();
        $response->assertHeader('X-Api-Security-Warn', 'true');
    }

    public function test_lifecycle_gate_blocks_soft_and_hard_states(): void
    {
        $cacheKey = sprintf('%s:%s', (string) config('api_security.lifecycle.cache_prefix'), hash('sha256', 'ip:127.0.0.1'));

        Cache::put($cacheKey, [
            'count' => 6,
            'last_violation_at' => time(),
            'soft_block_until' => time() + 30,
        ], 120);

        $soft = $this->postJson('/api/v1/_security_test/l2', ['payload' => 'soft']);
        $soft->assertStatus(429);
        $soft->assertJsonPath('code', 'soft_blocked');

        Cache::put($cacheKey, [
            'count' => 12,
            'last_violation_at' => time(),
            'hard_block_until' => time() + 30,
        ], 120);

        $hard = $this->postJson('/api/v1/_security_test/l2', ['payload' => 'hard']);
        $hard->assertStatus(403);
        $hard->assertJsonPath('code', 'hard_blocked');
    }

    public function test_lifecycle_violation_can_escalate_to_challenge_required(): void
    {
        config()->set('api_security.lifecycle.warn_after', 1);
        config()->set('api_security.lifecycle.challenge_after', 1);
        config()->set('api_security.lifecycle.soft_block_after', 50);
        config()->set('api_security.lifecycle.hard_block_after', 100);

        $response = $this->postJson('/api/v1/_security_test/l3', ['payload' => 'challenge']);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'challenge_required');
        $response->assertJson(fn ($json) => $json->whereType('retry_after', 'integer')->etc());
    }

    public function test_tenant_override_is_monotonic_and_cannot_downgrade_admin_level(): void
    {
        $downgradeAttempt = $this->postJson('/admin/api/v1/tenant-l1/_security_test/tenant-level', ['payload' => 'alpha']);
        $downgradeAttempt->assertOk();
        $downgradeAttempt->assertHeader('X-Api-Security-Level', 'L2');
        $downgradeAttempt->assertHeader('X-Api-Security-Level-Source', 'system_default');
    }

    public function test_tenant_override_can_strengthen_to_l3_and_require_idempotency(): void
    {
        $strengthened = $this->postJson('/admin/api/v1/tenant-l3/_security_test/tenant-level', ['payload' => 'alpha']);
        $strengthened->assertStatus(422);
        $strengthened->assertJsonPath('code', 'idempotency_missing');
        $strengthened->assertHeader('X-Api-Security-Level', 'L3');
        $strengthened->assertHeader('X-Api-Security-Level-Source', 'tenant_override');
    }

    public function test_cross_domain_risk_matrix_contracts_are_applied_consistently(): void
    {
        config()->set('api_security.lifecycle.enabled', false);
        Cache::flush();

        $checkout = $this->postJson('/api/v1/_security_test/checkout/confirm', ['payload' => 'checkout']);
        $checkout->assertStatus(422);
        $checkout->assertJsonPath('code', 'idempotency_missing');
        $checkout->assertHeader('X-Api-Security-Level', 'L3');

        $admission = $this->postJson('/api/v1/_security_test/events/event-1/occurrences/occ-1/admission', ['payload' => 'admission']);
        $admission->assertStatus(422);
        $admission->assertJsonPath('code', 'idempotency_missing');
        $admission->assertHeader('X-Api-Security-Level', 'L3');

        $settings = $this->patchJson('/api/v1/_security_test/settings/values/map_ui', ['default_origin' => ['lat' => -20.0]]);
        $settings->assertOk();
        $settings->assertHeader('X-Api-Security-Level', 'L2');

        $events = $this->postJson('/api/v1/_security_test/events/admin', ['name' => 'launch']);
        $events->assertOk();
        $events->assertHeader('X-Api-Security-Level', 'L2');
    }

    public function test_rate_limit_buckets_are_scoped_by_security_domain(): void
    {
        config()->set('api_security.lifecycle.enabled', false);
        Cache::flush();

        $alphaOne = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.41'])
            ->postJson('/api/v1/_security_test/domain-alpha', ['payload' => 'alpha-1']);
        $alphaOne->assertOk();
        $alphaOne->assertHeader('X-Api-Security-Domain', 'security_test_domain_alpha');

        $alphaTwo = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.41'])
            ->postJson('/api/v1/_security_test/domain-alpha', ['payload' => 'alpha-2']);
        $alphaTwo->assertStatus(429);
        $alphaTwo->assertHeader('X-Api-Security-Domain', 'security_test_domain_alpha');

        $betaOne = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.41'])
            ->postJson('/api/v1/_security_test/domain-beta', ['payload' => 'beta-1']);
        $betaOne->assertOk();
        $betaOne->assertHeader('X-Api-Security-Domain', 'security_test_domain_beta');

        $betaTwo = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.41'])
            ->postJson('/api/v1/_security_test/domain-beta', ['payload' => 'beta-2']);
        $betaTwo->assertOk();

        $betaThree = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.41'])
            ->postJson('/api/v1/_security_test/domain-beta', ['payload' => 'beta-3']);
        $betaThree->assertOk();

        $betaFour = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.41'])
            ->postJson('/api/v1/_security_test/domain-beta', ['payload' => 'beta-4']);
        $betaFour->assertStatus(429);
        $betaFour->assertHeader('X-Api-Security-Domain', 'security_test_domain_beta');
    }

    public function test_subject_rate_limit_bucket_applies_across_ips_for_public_login_routes(): void
    {
        config()->set('api_security.lifecycle.enabled', false);
        Cache::flush();

        $first = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.71'])
            ->postJson('/api/v1/_security_test/public-login', [
                'email' => 'shared-login@example.org',
                'password' => 'Secret!234',
            ]);
        $first->assertOk();
        $first->assertHeader('X-Api-Security-Domain', 'security_test_public_login');

        $second = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.72'])
            ->postJson('/api/v1/_security_test/public-login', [
                'email' => 'SHARED-LOGIN@example.org',
                'password' => 'Secret!234',
            ]);
        $second->assertStatus(429);
        $second->assertHeader('X-Api-Security-Domain', 'security_test_public_login');

        $control = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.73'])
            ->postJson('/api/v1/_security_test/public-login', [
                'email' => 'different-login@example.org',
                'password' => 'Secret!234',
            ]);
        $control->assertOk();
        $control->assertHeader('X-Api-Security-Domain', 'security_test_public_login');
    }

    public function test_abuse_signal_records_are_persisted_for_violations(): void
    {
        $this->postJson('/api/v1/_security_test/l3', ['payload' => 'no-idempotency'])
            ->assertStatus(422);

        $this->assertGreaterThan(0, ApiAbuseSignal::query()->count());
        $this->assertGreaterThan(0, ApiAbuseSignalAggregate::query()->count());
    }

    public function test_rate_limiter_backend_error_fails_open_by_default(): void
    {
        RateLimiter::shouldReceive('tooManyAttempts')
            ->once()
            ->andThrow(new RuntimeException('rate limiter backend unavailable'));

        $response = $this->postJson('/api/v1/_security_test/l2', ['payload' => 'alpha']);
        $response->assertOk()->assertJsonPath('ok', true);
    }

    public function test_rate_limiter_backend_error_can_fail_closed_when_configured(): void
    {
        config()->set('api_security.rate_limit.fail_closed_on_backend_error', true);

        RateLimiter::shouldReceive('tooManyAttempts')
            ->once()
            ->andThrow(new RuntimeException('rate limiter backend unavailable'));

        $response = $this->postJson('/api/v1/_security_test/l2', ['payload' => 'alpha']);
        $response->assertStatus(503);
        $response->assertJsonPath('code', 'rate_limit_unavailable');
    }

    public function test_public_login_routes_fail_closed_when_the_rate_limiter_backend_errors(): void
    {
        RateLimiter::shouldReceive('tooManyAttempts')
            ->once()
            ->andThrow(new RuntimeException('rate limiter backend unavailable'));

        $response = $this->postJson('/api/v1/_security_test/public-login', [
            'email' => 'subject@example.org',
            'password' => 'Secret!234',
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('code', 'rate_limit_unavailable');
        $response->assertHeader('X-Api-Security-Domain', 'security_test_public_login');
    }

    public function test_public_auth_routes_have_explicit_risk_matrix_entries(): void
    {
        $matrix = collect((array) config('api_security.risk_matrix', []))
            ->keyBy(static fn (array $entry): string => (string) ($entry['domain'] ?? ''));

        $expectations = [
            'tenant_public_anonymous_identity' => ['pattern' => '#^api/v1/anonymous/identities$#', 'requests_per_minute' => 30, 'subject_requests_per_minute' => 30],
            'tenant_public_phone_otp_challenge' => ['pattern' => '#^api/v1/auth/otp/challenge$#', 'requests_per_minute' => 30, 'subject_requests_per_minute' => 30],
            'tenant_public_phone_otp_verify' => ['pattern' => '#^api/v1/auth/otp/verify$#', 'requests_per_minute' => 60, 'subject_requests_per_minute' => 60],
            'tenant_public_password_login' => ['pattern' => '#^api/v1/auth/login$#', 'requests_per_minute' => 20, 'subject_requests_per_minute' => 20],
            'tenant_public_password_register' => ['pattern' => '#^api/v1/auth/register/password$#', 'requests_per_minute' => 20, 'subject_requests_per_minute' => 20],
            'tenant_public_password_reset_token' => ['pattern' => '#^api/v1/auth/password_token$#', 'requests_per_minute' => 10, 'subject_requests_per_minute' => 10],
            'tenant_public_password_reset' => ['pattern' => '#^api/v1/auth/password_reset$#', 'requests_per_minute' => 10, 'subject_requests_per_minute' => 10],
            'landlord_public_password_login' => ['pattern' => '#^admin/api/v1/auth/login$#', 'requests_per_minute' => 20, 'subject_requests_per_minute' => 20],
            'landlord_public_password_reset_token' => ['pattern' => '#^admin/api/v1/auth/password_token$#', 'requests_per_minute' => 10, 'subject_requests_per_minute' => 10],
            'landlord_public_password_reset' => ['pattern' => '#^admin/api/v1/auth/password_reset$#', 'requests_per_minute' => 10, 'subject_requests_per_minute' => 10],
        ];

        foreach ($expectations as $domain => $expected) {
            $entry = $matrix->get($domain);

            $this->assertIsArray($entry, "Expected risk-matrix entry for {$domain}.");
            $this->assertSame($expected['pattern'], $entry['pattern'] ?? null);
            $this->assertSame(['POST'], $entry['methods'] ?? null);
            $this->assertSame('L2', $entry['level'] ?? null);
            $this->assertSame(false, $entry['require_idempotency'] ?? null);
            $this->assertSame($expected['requests_per_minute'], $entry['requests_per_minute'] ?? null);
            $this->assertSame($expected['subject_requests_per_minute'], $entry['subject_requests_per_minute'] ?? null);
        }
    }

    public function test_public_auth_risk_matrix_patterns_match_real_post_routes(): void
    {
        $expectations = [
            'tenant_public_anonymous_identity' => '/api/v1/anonymous/identities',
            'tenant_public_phone_otp_challenge' => '/api/v1/auth/otp/challenge',
            'tenant_public_phone_otp_verify' => '/api/v1/auth/otp/verify',
            'tenant_public_password_login' => '/api/v1/auth/login',
            'tenant_public_password_register' => '/api/v1/auth/register/password',
            'tenant_public_password_reset_token' => '/api/v1/auth/password_token',
            'tenant_public_password_reset' => '/api/v1/auth/password_reset',
            'landlord_public_password_login' => '/admin/api/v1/auth/login',
            'landlord_public_password_reset_token' => '/admin/api/v1/auth/password_token',
            'landlord_public_password_reset' => '/admin/api/v1/auth/password_reset',
        ];

        $matrix = collect((array) config('api_security.risk_matrix', []))
            ->keyBy(static fn (array $entry): string => (string) ($entry['domain'] ?? ''));

        foreach ($expectations as $domain => $path) {
            $entry = $matrix->get($domain);
            $this->assertIsArray($entry, "Expected risk-matrix entry for {$domain}.");

            $route = app('router')->getRoutes()->match(Request::create($path, 'POST'));
            $this->assertSame(ltrim($path, '/'), $route->uri());
            $this->assertSame(1, preg_match((string) ($entry['pattern'] ?? ''), ltrim($path, '/')));
        }
    }

    public function test_tenant_public_password_routes_remain_guarded_by_explicit_auth_method_middleware(): void
    {
        foreach ([
            '/api/v1/auth/login',
            '/api/v1/auth/register/password',
            '/api/v1/auth/password_token',
            '/api/v1/auth/password_reset',
        ] as $path) {
            $route = app('router')->getRoutes()->match(Request::create($path, 'POST'));

            $this->assertContains(
                'App\\Http\\Middleware\\EnsureTenantPublicAuthMethod:password',
                $route->gatherMiddleware(),
                sprintf('Expected `%s` to remain protected by EnsureTenantPublicAuthMethod::class.:password.', $path),
            );
        }
    }

    private function setTrustedProxiesEnv(string $value): void
    {
        putenv(sprintf('TRUSTED_PROXIES=%s', $value));
        $_ENV['TRUSTED_PROXIES'] = $value;
        $_SERVER['TRUSTED_PROXIES'] = $value;
    }
}
