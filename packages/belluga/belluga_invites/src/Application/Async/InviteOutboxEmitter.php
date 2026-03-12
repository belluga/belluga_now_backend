<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Async;

use Belluga\Invites\Models\Tenants\InviteOutboxEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InviteOutboxEmitter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $topic, array $payload, ?string $receiverUserId = null): InviteOutboxEvent
    {
        return InviteOutboxEvent::query()->create([
            'topic' => $topic,
            'status' => 'pending',
            'receiver_user_id' => $receiverUserId,
            'payload' => $payload,
            'dedupe_key' => (string) Str::uuid(),
            'available_at' => Carbon::now(),
            'processed_at' => null,
            'attempts' => 0,
            'last_error' => null,
        ]);
    }
}
