<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Async;

use Belluga\Ticketing\Models\Tenants\TicketOutboxEvent;
use Illuminate\Support\Carbon;

class TicketOutboxEmitter
{
    /**
     * @param array<string, mixed> $payload
     */
    public function emit(string $topic, array $payload, string $dedupeKey): void
    {
        TicketOutboxEvent::query()->firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'topic' => $topic,
                'status' => 'pending',
                'payload' => $payload,
                'available_at' => Carbon::now(),
                'attempts' => 0,
            ]
        );
    }
}
