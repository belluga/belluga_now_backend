<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Landlord\ApiAbuseSignal;
use App\Models\Landlord\ApiAbuseSignalAggregate;
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

        $overrides = (array) config('api_security.route_overrides', []);
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/l3$#',
            'methods' => ['POST'],
            'level' => 'L3',
            'require_idempotency' => true,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/l2$#',
            'methods' => ['POST'],
            'level' => 'L2',
            'require_idempotency' => false,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/checkout/confirm$#',
            'methods' => ['POST'],
            'level' => 'L3',
            'require_idempotency' => true,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/events/[^/]+/occurrences/[^/]+/admission$#',
            'methods' => ['POST'],
            'level' => 'L3',
            'require_idempotency' => true,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/settings/values/[^/]+$#',
            'methods' => ['PATCH'],
            'level' => 'L2',
            'require_idempotency' => false,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/events/admin$#',
            'methods' => ['POST'],
            'level' => 'L2',
            'require_idempotency' => false,
        ];
        $overrides[] = [
            'pattern' => '#^api/v1/_security_test/account_onboardings$#',
            'methods' => ['POST'],
            'level' => 'L2',
            'require_idempotency' => false,
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

    private function setTrustedProxiesEnv(string $value): void
    {
        putenv(sprintf('TRUSTED_PROXIES=%s', $value));
        $_ENV['TRUSTED_PROXIES'] = $value;
        $_SERVER['TRUSTED_PROXIES'] = $value;
    }
}
