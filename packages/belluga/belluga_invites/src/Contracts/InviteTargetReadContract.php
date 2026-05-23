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
     *     event_image_url:?string,
     *     attributes:array<string,mixed>
     * }|null
     */
    public function findEventByIdOrSlug(string $eventRef): ?array;

    /**
     * @return array{
     *     id:string,
     *     starts_at:mixed,
     *     ends_at:mixed,
     *     effective_ends_at:mixed,
     *     is_event_published:bool,
     *     attributes:array<string,mixed>
     * }|null
     */
    public function findOccurrenceForEvent(string $eventId, string $occurrenceRef): ?array;

    /**
     * @return array{
     *     id:string,
     *     event_id:string,
     *     starts_at:mixed,
     *     ends_at:mixed,
     *     effective_ends_at:mixed,
     *     is_event_published:bool,
     *     attributes:array<string,mixed>
     * }|null
     */
    public function findOccurrenceByIdOrSlug(string $occurrenceRef): ?array;

    /**
     * @param  positive-int  $limit
     */
    public function countOccurrencesForEvent(string $eventId, int $limit = 2): int;
}
