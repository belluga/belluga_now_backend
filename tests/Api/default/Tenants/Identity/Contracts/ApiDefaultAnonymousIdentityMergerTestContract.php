<?php

declare(strict_types=1);

namespace Tests\Api\default\Tenants\Identity\Contracts;

use App\Domain\FoundationControlPlane\Identity\AnonymousIdentityMerger;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\IdentityMergeAudit;
use App\Models\Tenants\MergedAccountSnapshot;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Tests\TestCaseTenant;

abstract class ApiDefaultAnonymousIdentityMergerTestContract extends TestCaseTenant
{
    private Tenant $tenantModel;

    /** @var list<string> */
    private array $trackedUserIds = [];

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

        Collection::make($this->trackedUserIds)
            ->map(static fn (string $id): ObjectId => new ObjectId($id))
            ->each(static function (ObjectId $objectId): void {
                /** @var AccountUser|null $user */
                $user = AccountUser::withTrashed()->find($objectId);
                if ($user !== null) {
                    $user->forceDelete();
                }
            });

        $this->trackedUserIds = [];
        Tenant::forgetCurrent();

        parent::tearDown();
    }

    public function testMergePersistsOperatorMetadata(): void
    {
        $target = $this->createCanonicalUser();
        $source = $this->createAnonymousSource();

        $operatorId = (string) new ObjectId();
        $reason = 'manual_review';

        $this->merger()->merge($target, [$source], $operatorId, $reason);

        $audit = IdentityMergeAudit::query()
            ->where('canonical_user_id', new ObjectId((string) $target->_id))
            ->firstOrFail();

        $this->assertEquals($operatorId, (string) Arr::get($audit->operator ?? [], 'id'));
        $this->assertEquals($reason, Arr::get($audit->operator ?? [], 'reason'));
    }

    public function testMergeAggregatesTimelineAcrossSources(): void
    {
        $target = $this->createCanonicalUser();
        $initialRegisteredAt = $target->registered_at?->copy();

        $firstSeen = Carbon::parse('2024-12-31 08:15:00', 'UTC');
        $lastSeen = Carbon::parse('2025-01-05 13:45:00', 'UTC');

        $sourceWithFingerprint = $this->createAnonymousSource([
            'fingerprints' => [[
                'hash' => hash('sha256', Str::uuid()->toString()),
                'first_seen_at' => $firstSeen,
                'last_seen_at' => $lastSeen,
                'user_agent' => 'TestAgent/1.0',
            ]],
        ]);

        $olderCreatedAt = Carbon::parse('2024-10-01 12:00:00', 'UTC');
        $newerUpdatedAt = Carbon::parse('2025-02-15 09:30:00', 'UTC');

        $sourceWithTimestampsOnly = $this->createAnonymousSource([
            'fingerprints' => [],
            'created_at' => $olderCreatedAt,
            'updated_at' => $newerUpdatedAt,
        ]);

        $this->merger()->merge($target, [$sourceWithFingerprint, $sourceWithTimestampsOnly]);

        $audit = IdentityMergeAudit::query()
            ->where('canonical_user_id', new ObjectId((string) $target->_id))
            ->firstOrFail();

        $timeline = $audit->timeline ?? [];
        $this->assertNotEmpty($timeline);

        $this->assertTrue(
            $this->toCarbon($timeline['first_seen_at'] ?? null)->equalTo($olderCreatedAt),
            'Expected timeline.first_seen_at to match the earliest created_at fallback.'
        );
        $this->assertTrue(
            $this->toCarbon($timeline['last_seen_at'] ?? null)->equalTo($newerUpdatedAt),
            'Expected timeline.last_seen_at to match the latest updated_at fallback.'
        );

        $refreshedTarget = $target->fresh();
        $this->assertInstanceOf(Carbon::class, $refreshedTarget->first_seen_at);
        $this->assertTrue($refreshedTarget->first_seen_at->equalTo($olderCreatedAt));
        if ($initialRegisteredAt !== null) {
            $this->assertTrue($refreshedTarget->registered_at?->equalTo($initialRegisteredAt));
        }
    }

    public function testMergePreservesSourcePromotionAudit(): void
    {
        $target = $this->createCanonicalUser();

        $sourcePromotionTrail = [
            [
                'from_state' => null,
                'to_state' => 'anonymous',
                'promoted_at' => Carbon::parse('2024-07-01 09:00:00', 'UTC'),
            ],
            [
                'from_state' => 'anonymous',
                'to_state' => 'registered',
                'promoted_at' => Carbon::parse('2024-08-15 11:30:00', 'UTC'),
                'operator_id' => new ObjectId(),
            ],
        ];

        $source = $this->createAnonymousSource([
            'promotion_audit' => $sourcePromotionTrail,
        ]);

        $this->merger()->merge($target, [$source]);

        $audit = IdentityMergeAudit::query()
            ->where('canonical_user_id', new ObjectId((string) $target->_id))
            ->firstOrFail();

        $sources = $audit->sources ?? [];
        $this->assertCount(1, $sources);

        $this->assertEquals(
            $this->normalisePromotionAudit($sourcePromotionTrail),
            $this->normalisePromotionAudit($sources[0]['promotion_audit'] ?? [])
        );

        $refreshedTarget = $target->fresh();
        $timelineFirst = $this->toCarbon($audit->timeline['first_seen_at'] ?? null);
        $this->assertInstanceOf(Carbon::class, $refreshedTarget->first_seen_at);
        $this->assertTrue($refreshedTarget->first_seen_at->equalTo($timelineFirst));
    }

    public function testMergeDoesNotEmitAuditWhenSourcesAreEmpty(): void
    {
        $target = $this->createCanonicalUser();
        $initialFirstSeen = $target->first_seen_at?->copy();
        $initialRegisteredAt = $target->registered_at?->copy();

        $this->merger()->merge($target, []);

        $this->assertEquals(0, IdentityMergeAudit::query()->count());
        $refreshedTarget = $target->fresh();
        $this->assertEquals([], $refreshedTarget->merged_source_ids ?? []);
        if ($initialFirstSeen !== null) {
            $this->assertTrue($refreshedTarget->first_seen_at?->equalTo($initialFirstSeen));
        }
        if ($initialRegisteredAt !== null) {
            $this->assertTrue($refreshedTarget->registered_at?->equalTo($initialRegisteredAt));
        }
    }

    public function testSuccessiveMergesCreateDiscreteAuditRecords(): void
    {
        $target = $this->createCanonicalUser();

        $firstSource = $this->createAnonymousSource();
        $secondSource = $this->createAnonymousSource();

        $firstReason = 'first_wave';
        $secondReason = 'second_wave';

        $this->merger()->merge($target, [$firstSource], (string) new ObjectId(), $firstReason);
        $this->merger()->merge($target->fresh(), [$secondSource], (string) new ObjectId(), $secondReason);

        $audits = IdentityMergeAudit::query()
            ->where('canonical_user_id', new ObjectId((string) $target->_id))
            ->orderBy('consolidated_at')
            ->get();

        $this->assertCount(2, $audits);

        $this->assertEquals(
            $firstReason,
            Arr::get($audits->first()->operator ?? [], 'reason')
        );
        $this->assertEquals(
            $secondReason,
            Arr::get($audits->last()->operator ?? [], 'reason')
        );

        $refreshedTarget = $target->fresh();
        $this->assertInstanceOf(Carbon::class, $refreshedTarget->first_seen_at);
        $this->assertTrue(
            $refreshedTarget->first_seen_at->equalTo(
                $this->toCarbon($audits->last()->timeline['first_seen_at'] ?? null)
            )
        );

        $mergedIds = $refreshedTarget->merged_source_ids ?? [];
        $this->assertEqualsCanonicalizing(
            [(string) $firstSource->_id, (string) $secondSource->_id],
            $mergedIds
        );
    }

    private function merger(): AnonymousIdentityMerger
    {
        return app(AnonymousIdentityMerger::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createCanonicalUser(array $overrides = []): AccountUser
    {
        $payload = array_merge([
            'name' => 'Canonical ' . Str::random(8),
            'identity_state' => 'registered',
            'emails' => [sprintf('canonical-%s@example.org', Str::uuid())],
            'first_seen_at' => Carbon::now(),
            'registered_at' => Carbon::now(),
            'fingerprints' => [],
            'credentials' => [],
            'consents' => [],
            'merged_source_ids' => [],
            'promotion_audit' => [[
                'from_state' => null,
                'to_state' => 'registered',
                'promoted_at' => Carbon::now(),
                'operator_id' => null,
                'source_user_id' => null,
                'reason' => null,
            ]],
        ], $overrides);

        $timestampPayload = [
            'created_at' => Arr::pull($payload, 'created_at', null),
            'updated_at' => Arr::pull($payload, 'updated_at', null),
        ];

        /** @var AccountUser $user */
        $user = AccountUser::create($payload);

        $this->applyTimestamps($user, $timestampPayload);
        $this->trackedUserIds[] = (string) $user->_id;

        return $user;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createAnonymousSource(array $overrides = []): AccountUser
    {
        $payload = array_merge([
            'name' => 'Anonymous ' . Str::random(8),
            'identity_state' => 'anonymous',
            'first_seen_at' => Carbon::now()->subDay(),
            'fingerprints' => [[
                'hash' => hash('sha256', Str::uuid()->toString()),
                'first_seen_at' => Carbon::now()->subDay(),
                'last_seen_at' => Carbon::now(),
                'user_agent' => 'AnonymousTest/1.0',
            ]],
            'consents' => [],
            'credentials' => [],
            'emails' => [],
            'phones' => [],
            'promotion_audit' => [],
        ], $overrides);

        $timestampPayload = [
            'created_at' => Arr::pull($payload, 'created_at', null),
            'updated_at' => Arr::pull($payload, 'updated_at', null),
        ];

        /** @var AccountUser $user */
        $user = AccountUser::create($payload);

        $this->applyTimestamps($user, $timestampPayload);
        $this->trackedUserIds[] = (string) $user->_id;

        return $user;
    }

    /**
     * @param mixed $value
     */
    private function toCarbon(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof UTCDateTime) {
            return Carbon::instance($value->toDateTime());
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        return Carbon::parse((string) $value);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function normalisePromotionAudit(array $entries): array
    {
        return Collection::make($entries)
            ->map(function (array $entry): array {
                return Collection::make($entry)
                    ->map(fn ($value) => $this->scalarise($value))
                    ->toArray();
            })
            ->toArray();
    }

    /**
     * @param array{created_at:mixed|null,updated_at:mixed|null} $timestamps
     */
    private function applyTimestamps(AccountUser $user, array $timestamps): void
    {
        $dirty = false;

        if ($timestamps['created_at'] !== null) {
            $user->setAttribute('created_at', $timestamps['created_at']);
            $dirty = true;
        }

        if ($timestamps['updated_at'] !== null) {
            $user->setAttribute('updated_at', $timestamps['updated_at']);
            $dirty = true;
        }

        if ($dirty) {
            $user->save();
        }
    }

    private function scalarise(mixed $value): mixed
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->setTimezone('UTC')->format(DATE_ATOM);
        }

        if ($value instanceof Carbon) {
            return $value->copy()->setTimezone('UTC')->format(DATE_ATOM);
        }

        if ($value instanceof ObjectId) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone('UTC')->format(DATE_ATOM);
        }

        return $value;
    }
}
