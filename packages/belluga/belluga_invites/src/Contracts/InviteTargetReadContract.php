<?php

declare(strict_types=1);

namespace Belluga\Invites\Contracts;

interface InviteTargetReadContract
{
    /**
     * @return array{
     *     id:string,
     *     slug:string,
     *     title:string,
     *     date_time_start:mixed,
     *     date_time_end:mixed,
     *     publication:mixed,
     *     attributes:array<string,mixed>
     * }|null
     */
    public function findEventByIdOrSlug(string $eventRef): ?array;

    /**
     * @return array{
     *     id:string,
     *     starts_at:mixed,
     *     ends_at:mixed,
     *     is_event_published:bool,
     *     attributes:array<string,mixed>
     * }|null
     */
    public function findOccurrenceForEvent(string $eventId, string $occurrenceRef): ?array;

    /**
     * @param  positive-int  $limit
     */
    public function countOccurrencesForEvent(string $eventId, int $limit = 2): int;
}
