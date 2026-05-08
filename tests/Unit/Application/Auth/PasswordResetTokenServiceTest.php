<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Auth;

use App\Application\Auth\PasswordResetTokenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class PasswordResetTokenServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private PasswordResetTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshLandlordAndTenantDatabases();
        $this->service = $this->app->make(PasswordResetTokenService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_persists_only_a_hashed_expiring_token(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-07T12:00:00Z'));

        $token = $this->service->issueForUser(
            userId: 'user-1',
            email: 'person@example.org',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
        );

        $record = DB::connection('landlord')
            ->table('password_reset_tokens')
            ->where('user_id', 'user-1')
            ->first();

        $this->assertNotNull($record);
        $this->assertTrue(Hash::check($token, (string) $record->token_hash));
        $this->assertNotSame($token, (string) ($record->token_lookup_hash ?? ''));
        $this->assertFalse(property_exists($record, 'token'));
        $this->assertNotNull($record->created_at ?? null);
        $this->assertNotNull($record->expires_at ?? null);
    }

    #[Test]
    public function it_consumes_tokens_as_single_use(): void
    {
        $token = $this->service->issueForUser(
            userId: 'user-2',
            email: 'person@example.org',
            broker: PasswordResetTokenService::LANDLORD_USERS_BROKER,
        );

        $this->service->consumeForUser('user-2', $token, PasswordResetTokenService::LANDLORD_USERS_BROKER);

        $this->assertNull(
            DB::connection('landlord')
                ->table('password_reset_tokens')
                ->where('user_id', 'user-2')
                ->first()
        );

        $this->expectException(ValidationException::class);

        $this->service->consumeForUser('user-2', $token, PasswordResetTokenService::LANDLORD_USERS_BROKER);
    }

    #[Test]
    public function it_reports_invalid_token_attempts_without_throwing_during_probe_consumption(): void
    {
        $this->assertFalse(
            $this->service->attemptConsumeForUser(
                userId: 'missing-user',
                token: 'invalid-token',
                broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            )
        );
    }

    #[Test]
    public function it_rejects_invalid_reset_attempts_with_the_canonical_error_shape(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->rejectInvalidResetAttempt(
            token: 'invalid-token',
            password: 'Password123!',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
        );
    }

    #[Test]
    public function it_rejects_expired_tokens(): void
    {
        $token = $this->service->issueForUser(
            userId: 'user-3',
            email: 'person@example.org',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
        );

        DB::connection('landlord')
            ->table('password_reset_tokens')
            ->where('user_id', 'user-3')
            ->update([
                'expires_at' => now()->subSecond(),
            ]);

        $this->expectException(ValidationException::class);

        $this->service->consumeForUser('user-3', $token, PasswordResetTokenService::TENANT_USERS_BROKER);
    }

    #[Test]
    public function it_invalidates_the_previous_token_when_a_new_one_is_issued(): void
    {
        $firstToken = $this->service->issueForUser(
            userId: 'user-4',
            email: 'person@example.org',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
        );

        $secondToken = $this->service->issueForUser(
            userId: 'user-4',
            email: 'person@example.org',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
        );

        $this->assertNotSame($firstToken, $secondToken);

        $this->expectException(ValidationException::class);

        $this->service->consumeForUser('user-4', $firstToken, PasswordResetTokenService::TENANT_USERS_BROKER);
    }

    #[Test]
    public function it_isolates_tenant_cooldowns_and_token_slots_by_scope(): void
    {
        $this->assertTrue($this->service->acquireIssueCooldown(
            email: 'shared@example.org',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: 'tenant-a',
        ));
        $this->assertTrue($this->service->acquireIssueCooldown(
            email: 'shared@example.org',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: 'tenant-b',
        ));
        $this->assertFalse($this->service->acquireIssueCooldown(
            email: 'shared@example.org',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: 'tenant-a',
        ));

        $tenantAToken = $this->service->issueForUser(
            userId: 'shared-user',
            email: 'shared@example.org',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: 'tenant-a',
        );
        $tenantBToken = $this->service->issueForUser(
            userId: 'shared-user',
            email: 'shared@example.org',
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: 'tenant-b',
        );

        $records = DB::connection('landlord')
            ->table('password_reset_tokens')
            ->where('user_id', 'shared-user')
            ->orderBy('scope_key')
            ->get();

        $this->assertCount(2, $records);
        $this->assertSame('tenant-a', (string) ($records[0]->scope_key ?? ''));
        $this->assertSame('tenant-b', (string) ($records[1]->scope_key ?? ''));

        try {
            $this->service->consumeForUser(
                userId: 'shared-user',
                token: $tenantAToken,
                broker: PasswordResetTokenService::TENANT_USERS_BROKER,
                scope: 'tenant-b',
            );
            $this->fail('Expected cross-scope token consumption to be rejected.');
        } catch (ValidationException) {
        }

        $this->service->consumeForUser(
            userId: 'shared-user',
            token: $tenantAToken,
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: 'tenant-a',
        );
        $this->service->consumeForUser(
            userId: 'shared-user',
            token: $tenantBToken,
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: 'tenant-b',
        );
    }
}
