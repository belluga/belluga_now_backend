<?php

namespace Tests\Api\v1\Tenants\Auth\Contracts;

use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Domain\Identity\AnonymousIdentityMerger;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\IdentityMergeAudit;
use App\Models\Tenants\MergedAccountSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Mockery;
use MongoDB\BSON\ObjectId;
use Tests\Helpers\UserLabels;
use Tests\TestCaseTenant;

abstract class ApiV1PasswordRegistrationTestContract extends TestCaseTenant
{
    private Tenant $tenantModel;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::forgetCurrent();
        $this->tenantModel = Tenant::query()
            ->where('slug', $this->tenant->slug)
            ->firstOrFail();
        $this->tenantModel->makeCurrent();
    }

    protected function tearDown(): void
    {
        $this->tenantModel->makeCurrent();

        IdentityMergeAudit::query()->delete();
        MergedAccountSnapshot::query()->delete();

        Tenant::forgetCurrent();

        parent::tearDown();
    }

    protected function registrationEndpoint(): string
    {
        return sprintf('%sauth/register/password', $this->base_api_tenant);
    }

    protected function registerPassword(array $payload): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: $this->registrationEndpoint(),
            data: $payload
        );
    }

    public function testPasswordRegistrationCreatesRegisteredIdentity(): void
    {
        $payload = [
            'name' => 'Registered Identity',
            'email' => 'registered-identity@example.org',
            'password' => 'SecurePass!123',
        ];

        $response = $this->registerPassword($payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'user_id',
                'identity_state',
                'token',
            ],
        ]);
        $response->assertJsonPath('data.identity_state', 'registered');

        $userId = $response->json('data.user_id');

        $label = new UserLabels("{$this->tenant->subdomain}.password.registration");
        $label->user_id = $userId;
        $label->token = $response->json('data.token');

        $this->tenantModel->makeCurrent();
        $user = AccountUser::query()->where('_id', new ObjectId($userId))->firstOrFail();
        $this->assertEquals('registered', $user->identity_state);
        $this->assertContains($payload['email'], $user->emails);
        $this->assertInstanceOf(Carbon::class, $user->first_seen_at);
        $this->assertInstanceOf(Carbon::class, $user->registered_at);
        $this->assertTrue($user->first_seen_at->equalTo($user->registered_at));
        $this->assertNotEmpty($user->promotion_audit);
        $firstAudit = $user->promotion_audit[0];
        $this->assertNull($firstAudit['from_state'] ?? null);
        $this->assertEquals('registered', $firstAudit['to_state'] ?? null);
        $this->assertNull($firstAudit['source_user_id'] ?? null);
        $this->assertNull($firstAudit['reason'] ?? null);
    }

    public function testPasswordRegistrationRejectsDuplicateEmail(): void
    {
        $payload = [
            'name' => 'Duplicate Identity',
            'email' => 'duplicate-identity@example.org',
            'password' => 'SecurePass!123',
        ];

        $this->registerPassword($payload)->assertStatus(201);

        $duplicate = $this->registerPassword($payload);
        $duplicate->assertStatus(422);
        $duplicate->assertJsonPath('errors.email.0', 'This email is already registered for the tenant.');
    }

    public function testPasswordRegistrationRejectsPasswordExceedingMaxLength(): void
    {
        $payload = [
            'name' => 'Oversized Password Identity',
            'email' => 'oversized-password@example.org',
            'password' => str_repeat('A', 33),
        ];

        $response = $this->registerPassword($payload);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.password.0', 'The password field must not be greater than 32 characters.');
    }

    public function testPasswordRegistrationMergesAnonymousIdentities(): void
    {
        $firstHash = hash('sha256', 'merge-device-one');
        $secondHash = hash('sha256', 'merge-device-two');

        $firstAnonymous = $this->issueAnonymousIdentityForMerge($firstHash, 'merge-device-one');
        $secondAnonymous = $this->issueAnonymousIdentityForMerge($secondHash, 'merge-device-two');

        $payload = [
            'name' => 'Merged Identity',
            'email' => 'merged-identity@example.org',
            'password' => 'SecurePass!123',
            'anonymous_user_ids' => [
                $firstAnonymous['id'],
                $secondAnonymous['id'],
            ],
        ];

        $response = $this->registerPassword($payload);
        $response->assertStatus(201);

        $this->tenantModel->makeCurrent();

        $canonicalId = $response->json('data.user_id');
        $canonicalUser = AccountUser::query()
            ->where('_id', new ObjectId($canonicalId))
            ->firstOrFail();

        $this->assertEquals('registered', $canonicalUser->identity_state);
        $this->assertCount(2, $canonicalUser->fingerprints ?? []);
        $this->assertEqualsCanonicalizing(
            [$firstAnonymous['id'], $secondAnonymous['id']],
            $canonicalUser->merged_source_ids ?? []
        );
        $this->assertInstanceOf(Carbon::class, $canonicalUser->first_seen_at);
        $this->assertInstanceOf(Carbon::class, $canonicalUser->registered_at);
        $auditLog = $canonicalUser->promotion_audit ?? [];
        $this->assertCount(1, $auditLog);
        $this->assertEquals('registered', $auditLog[0]['to_state'] ?? null);
        $this->assertNull($auditLog[0]['source_user_id'] ?? null);
        $this->assertNull($auditLog[0]['reason'] ?? null);

        $mergeAudit = IdentityMergeAudit::query()
            ->where('canonical_user_id', new ObjectId($canonicalId))
            ->firstOrFail();

        $this->assertEquals($canonicalUser->identity_state, $mergeAudit->target_identity_state);
        $this->assertEquals(
            $canonicalUser->promotion_audit ?? [],
            $mergeAudit->target_promotion_audit_before_merge ?? []
        );
        $this->assertTrue(
            $canonicalUser->first_seen_at->equalTo($this->toCarbon($mergeAudit->timeline['first_seen_at'] ?? null))
        );
        $this->assertEqualsCanonicalizing(
            [$firstAnonymous['id'], $secondAnonymous['id']],
            collect($mergeAudit->merged_source_ids ?? [])->map(static fn ($id): string => (string) $id)->all()
        );
        $this->assertNotNull($mergeAudit->consolidated_at ?? null);
        $this->assertNotEmpty($mergeAudit->timeline ?? []);
        $this->assertArrayHasKey('first_seen_at', $mergeAudit->timeline);
        $this->assertArrayHasKey('last_seen_at', $mergeAudit->timeline);
        $this->assertCount(2, $mergeAudit->sources ?? []);
        collect($mergeAudit->sources ?? [])->each(function (array $source) use ($firstAnonymous, $secondAnonymous): void {
            $this->assertContains((string) ($source['source_user_id'] ?? ''), [$firstAnonymous['id'], $secondAnonymous['id']]);
            $this->assertArrayHasKey('promotion_audit', $source);
        });

        $snapshots = MergedAccountSnapshot::query()
            ->whereIn('source_user_id', [
                new ObjectId($firstAnonymous['id']),
                new ObjectId($secondAnonymous['id']),
            ])
            ->get();

        $this->assertCount(2, $snapshots);

        $this->assertFalse(
            AccountUser::query()
                ->whereIn('_id', [
                    new ObjectId($firstAnonymous['id']),
                    new ObjectId($secondAnonymous['id']),
                ])
                ->exists()
        );

        $invalidTokenCheck = $this->json(
            method: 'get',
            uri: "{$this->base_api_tenant}auth/token_validate",
            data: [],
            headers: [
                'Authorization' => "Bearer {$firstAnonymous['token']}",
            ]
        );
        $invalidTokenCheck->assertStatus(401);
    }

    public function testPasswordRegistrationRejectsUnknownAnonymousIdentity(): void
    {
        $payload = [
            'name' => 'Merge Candidate',
            'email' => 'merge-candidate@example.org',
            'password' => 'SecurePass!123',
            'anonymous_user_ids' => [
                (string) new ObjectId(),
            ],
        ];

        $response = $this->registerPassword($payload);
        $response->assertStatus(422);
        $response->assertJsonPath('errors.anonymous_user_ids.0', 'One or more anonymous identities could not be found.');
    }

    public function testPasswordRegistrationRejectsRegisteredIdentityDuringMerge(): void
    {
        $label = new UserLabels("{$this->tenant->subdomain}.password.registration");

        $this->assertNotEmpty($label->user_id, 'Expected an existing registered identity seeded earlier in the suite.');

        $this->tenantModel->makeCurrent();
        /** @var AccountUser $registeredIdentity */
        $registeredIdentity = AccountUser::query()
            ->where('_id', new ObjectId((string) $label->user_id))
            ->firstOrFail();
        Tenant::forgetCurrent();

        $payload = [
            'name' => 'Merge Candidate',
            'email' => 'merge-candidate-registered@example.org',
            'password' => 'SecurePass!123',
            'anonymous_user_ids' => [
                (string) $registeredIdentity->_id,
            ],
        ];

        $response = $this->registerPassword($payload);
        $response->assertStatus(422);
        $response->assertJsonPath('errors.anonymous_user_ids.0', 'Only anonymous identities can be merged during registration.');

        $this->tenantModel->makeCurrent();
        AccountUser::query()
            ->where('_id', $registeredIdentity->_id)
            ->forceDelete();
        Tenant::forgetCurrent();
    }

    public function testPasswordRegistrationReturnsConflictWhenMergeKeepsFailing(): void
    {
        $hash = hash('sha256', 'merge-device-conflict');
        $anonymousIdentity = $this->issueAnonymousIdentityForMerge($hash, 'merge-conflict');

        $mock = Mockery::mock(AnonymousIdentityMerger::class);
        $mock->shouldReceive('merge')
            ->times(3)
            ->andThrow(new ConcurrencyConflictException('Conflict'));

        $this->app->instance(AnonymousIdentityMerger::class, $mock);

        $payload = [
            'name' => 'Merge Conflict Candidate',
            'email' => 'merge-conflict@example.org',
            'password' => 'SecurePass!123',
            'anonymous_user_ids' => [
                $anonymousIdentity['id'],
            ],
        ];

        $response = $this->registerPassword($payload);
        $response->assertStatus(409);
        $response->assertJsonPath('message', 'A concurrency conflict occurred. Please try again.');
    }

    protected function issueAnonymousIdentityForMerge(string $hash, string $deviceName): array
    {
        $response = $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}anonymous/identities",
            data: [
                'device_name' => $deviceName,
                'fingerprint' => [
                    'hash' => $hash,
                    'user_agent' => 'MergeTest/1.0',
                ],
            ]
        );

        $response->assertStatus(201);

        return [
            'id' => $response->json('data.user_id'),
            'token' => $response->json('data.token'),
        ];
    }

    protected function toCarbon(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return Carbon::instance($value->toDateTime());
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        return Carbon::parse((string) $value);
    }
}
