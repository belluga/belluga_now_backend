<?php

declare(strict_types=1);

namespace Tests\Unit\Ticketing;

use Belluga\Ticketing\Application\Guards\OccurrenceWriteGuardService;
use Belluga\Ticketing\Contracts\OccurrencePublicationContract;
use Belluga\Ticketing\Contracts\OccurrenceReadContract;
use Belluga\Ticketing\Contracts\TicketingPolicyContract;
use Tests\TestCase;

class OccurrenceWriteGuardServiceTest extends TestCase
{
    public function test_it_rejects_when_ticketing_is_disabled(): void
    {
        $service = new OccurrenceWriteGuardService(
            new class implements TicketingPolicyContract
            {
                public function isTicketingEnabled(): bool
                {
                    return false;
                }

                public function identityMode(): string
                {
                    return 'auth_only';
                }
            },
            new class implements OccurrenceReadContract
            {
                public function findOccurrence(string $eventId, string $occurrenceId): ?array
                {
                    return ['id' => $occurrenceId, 'event_id' => $eventId];
                }

                public function resolveOccurrenceRefs(?string $eventRef, string $occurrenceRef): ?array
                {
                    return ['event_id' => (string) $eventRef, 'occurrence_id' => $occurrenceRef];
                }
            },
            new class implements OccurrencePublicationContract
            {
                public function isOccurrencePublished(string $eventId, string $occurrenceId): bool
                {
                    return true;
                }
            }
        );

        $result = $service->evaluate('ev-1', 'occ-1', true);

        $this->assertFalse($result['allowed']);
        $this->assertSame('ticketing_disabled', $result['code']);
    }

    public function test_it_rejects_when_auth_is_required_and_actor_is_anonymous(): void
    {
        $service = new OccurrenceWriteGuardService(
            new class implements TicketingPolicyContract
            {
                public function isTicketingEnabled(): bool
                {
                    return true;
                }

                public function identityMode(): string
                {
                    return 'auth_only';
                }
            },
            new class implements OccurrenceReadContract
            {
                public function findOccurrence(string $eventId, string $occurrenceId): ?array
                {
                    return ['id' => $occurrenceId, 'event_id' => $eventId];
                }

                public function resolveOccurrenceRefs(?string $eventRef, string $occurrenceRef): ?array
                {
                    return ['event_id' => (string) $eventRef, 'occurrence_id' => $occurrenceRef];
                }
            },
            new class implements OccurrencePublicationContract
            {
                public function isOccurrencePublished(string $eventId, string $occurrenceId): bool
                {
                    return true;
                }
            }
        );

        $result = $service->evaluate('ev-1', 'occ-1', false);

        $this->assertFalse($result['allowed']);
        $this->assertSame('auth_required', $result['code']);
    }

    public function test_it_rejects_when_occurrence_does_not_exist(): void
    {
        $service = new OccurrenceWriteGuardService(
            new class implements TicketingPolicyContract
            {
                public function isTicketingEnabled(): bool
                {
                    return true;
                }

                public function identityMode(): string
                {
                    return 'guest_or_auth';
                }
            },
            new class implements OccurrenceReadContract
            {
                public function findOccurrence(string $eventId, string $occurrenceId): ?array
                {
                    return null;
                }

                public function resolveOccurrenceRefs(?string $eventRef, string $occurrenceRef): ?array
                {
                    return ['event_id' => (string) $eventRef, 'occurrence_id' => $occurrenceRef];
                }
            },
            new class implements OccurrencePublicationContract
            {
                public function isOccurrencePublished(string $eventId, string $occurrenceId): bool
                {
                    return true;
                }
            }
        );

        $result = $service->evaluate('ev-1', 'occ-1', false);

        $this->assertFalse($result['allowed']);
        $this->assertSame('occurrence_not_found', $result['code']);
    }

    public function test_it_rejects_soft_deleted_occurrence(): void
    {
        $service = new OccurrenceWriteGuardService(
            new class implements TicketingPolicyContract
            {
                public function isTicketingEnabled(): bool
                {
                    return true;
                }

                public function identityMode(): string
                {
                    return 'guest_or_auth';
                }
            },
            new class implements OccurrenceReadContract
            {
                public function findOccurrence(string $eventId, string $occurrenceId): ?array
                {
                    return [
                        'id' => $occurrenceId,
                        'event_id' => $eventId,
                        'deleted_at' => '2026-01-01T00:00:00Z',
                    ];
                }

                public function resolveOccurrenceRefs(?string $eventRef, string $occurrenceRef): ?array
                {
                    return ['event_id' => (string) $eventRef, 'occurrence_id' => $occurrenceRef];
                }
            },
            new class implements OccurrencePublicationContract
            {
                public function isOccurrencePublished(string $eventId, string $occurrenceId): bool
                {
                    return true;
                }
            }
        );

        $result = $service->evaluate('ev-1', 'occ-1', false);

        $this->assertFalse($result['allowed']);
        $this->assertSame('occurrence_deleted', $result['code']);
    }

    public function test_it_rejects_when_occurrence_is_unpublished(): void
    {
        $service = new OccurrenceWriteGuardService(
            new class implements TicketingPolicyContract
            {
                public function isTicketingEnabled(): bool
                {
                    return true;
                }

                public function identityMode(): string
                {
                    return 'guest_or_auth';
                }
            },
            new class implements OccurrenceReadContract
            {
                public function findOccurrence(string $eventId, string $occurrenceId): ?array
                {
                    return ['id' => $occurrenceId, 'event_id' => $eventId];
                }

                public function resolveOccurrenceRefs(?string $eventRef, string $occurrenceRef): ?array
                {
                    return ['event_id' => (string) $eventRef, 'occurrence_id' => $occurrenceRef];
                }
            },
            new class implements OccurrencePublicationContract
            {
                public function isOccurrencePublished(string $eventId, string $occurrenceId): bool
                {
                    return false;
                }
            }
        );

        $result = $service->evaluate('ev-1', 'occ-1', false);

        $this->assertFalse($result['allowed']);
        $this->assertSame('occurrence_unpublished', $result['code']);
    }

    public function test_it_allows_when_guard_conditions_pass(): void
    {
        $service = new OccurrenceWriteGuardService(
            new class implements TicketingPolicyContract
            {
                public function isTicketingEnabled(): bool
                {
                    return true;
                }

                public function identityMode(): string
                {
                    return 'guest_or_auth';
                }
            },
            new class implements OccurrenceReadContract
            {
                public function findOccurrence(string $eventId, string $occurrenceId): ?array
                {
                    return [
                        'id' => $occurrenceId,
                        'event_id' => $eventId,
                        'starts_at' => '2026-03-10T19:00:00Z',
                        'ends_at' => '2026-03-10T22:00:00Z',
                        'deleted_at' => null,
                    ];
                }

                public function resolveOccurrenceRefs(?string $eventRef, string $occurrenceRef): ?array
                {
                    return ['event_id' => (string) $eventRef, 'occurrence_id' => $occurrenceRef];
                }
            },
            new class implements OccurrencePublicationContract
            {
                public function isOccurrencePublished(string $eventId, string $occurrenceId): bool
                {
                    return true;
                }
            }
        );

        $result = $service->evaluate('ev-1', 'occ-1', false);

        $this->assertTrue($result['allowed']);
        $this->assertSame('ok', $result['code']);
        $this->assertSame('occ-1', $result['occurrence']['id']);
    }
}
