<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

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

        Route::post('/api/v1/_security_test/l3', static function () {
            return response()->json(['ok' => true]);
        });

        Route::post('/api/v1/_security_test/l2', static function () {
            return response()->json(['ok' => true]);
        });

        config()->set('api_security.route_overrides', [
            [
                'pattern' => '#^api/v1/_security_test/l3$#',
                'level' => 'L3',
                'require_idempotency' => true,
            ],
            [
                'pattern' => '#^api/v1/_security_test/l2$#',
                'level' => 'L2',
                'require_idempotency' => false,
            ],
        ]);
        config()->set('api_security.cloudflare.enforce_origin_lock', false);
        config()->set('api_security.observe_mode', false);
        config()->set('api_security.levels.L3.requests_per_minute', 9999);
        config()->set('api_security.levels.L2.requests_per_minute', 9999);
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
        Cache::flush();
        config()->set('api_security.cloudflare.enforce_origin_lock', true);

        $blocked = $this->postJson('/api/v1/_security_test/l2', ['payload' => 'alpha']);
        $blocked->assertStatus(403);
        $blocked->assertJsonPath('code', 'origin_access_denied');

        $allowed = $this
            ->withHeaders(['CF-Ray' => 'abc-xyz'])
            ->postJson('/api/v1/_security_test/l2', ['payload' => 'alpha']);
        $allowed->assertOk()->assertJsonPath('ok', true);
        $allowed->assertHeader('X-CF-Ray-Id', 'abc-xyz');
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
}
