<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Landlord\ApiAbuseSignal;
use App\Models\Landlord\ApiAbuseSignalAggregate;
use App\Models\Landlord\LandlordUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Tests\TestCaseAuthenticated;

class ApiAbuseSignalsControllerTest extends TestCaseAuthenticated
{
    protected function setUp(): void
    {
        parent::setUp();

        ApiAbuseSignal::query()->delete();
        ApiAbuseSignalAggregate::query()->delete();
    }

    public function test_index_returns_aggregate_signals(): void
    {
        ApiAbuseSignalAggregate::query()->create([
            'bucket_at' => CarbonImmutable::now('UTC')->startOfHour(),
            'code' => 'rate_limited',
            'action' => 'warn',
            'level' => 'L2',
            'tenant_reference' => 'tenant-a',
            'method' => 'POST',
            'path' => '/api/v1/checkout/confirm',
            'observe_mode' => false,
            'count' => 3,
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
            'expires_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        $response = $this->json('get', 'admin/api/v1/security/abuse-signals?kind=aggregate', [], $this->getHeaders());

        $response->assertOk();
        $response->assertJsonPath('meta.kind', 'aggregate');
        $response->assertJsonPath('meta.count', 1);
        $response->assertJsonPath('data.0.code', 'rate_limited');
    }

    public function test_raw_kind_requires_specific_ability(): void
    {
        ApiAbuseSignal::query()->create([
            'kind' => 'violation',
            'code' => 'idempotency_missing',
            'action' => 'warn',
            'level' => 'L3',
            'level_source' => 'endpoint_override',
            'tenant_reference' => 'tenant-a',
            'principal_hash' => hash('sha256', 'x'),
            'method' => 'POST',
            'path' => '/api/v1/checkout/confirm',
            'observe_mode' => false,
            'blocked' => true,
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
            'expires_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        $user = LandlordUser::query()->firstOrFail();
        $token = $user->createToken('security-read-only', ['security-signals:read'])->plainTextToken;

        $response = $this->json('get', 'admin/api/v1/security/abuse-signals?kind=raw', [], [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'security_signal_access_denied');
    }

    public function test_summary_returns_grouped_counts_and_writes_audit_log(): void
    {
        ApiAbuseSignalAggregate::query()->create([
            'bucket_at' => CarbonImmutable::now('UTC')->startOfHour(),
            'code' => 'hard_blocked',
            'action' => 'hard_block',
            'level' => 'L3',
            'tenant_reference' => 'tenant-b',
            'method' => 'POST',
            'path' => '/api/v1/events/a/occurrences/b/admission',
            'observe_mode' => false,
            'count' => 2,
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
            'expires_at' => CarbonImmutable::now('UTC')->addDay(),
        ]);

        Log::spy();

        $response = $this->json('get', 'admin/api/v1/security/abuse-signals/summary?hours=24', [], $this->getHeaders());

        $response->assertOk();
        $response->assertJsonPath('data.total', 2);
        $response->assertJsonPath('data.grouped_by_code.hard_blocked', 2);

        Log::shouldHaveReceived('info')->once();
    }
}
