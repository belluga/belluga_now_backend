<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Realtime;

use Belluga\Invites\Models\Tenants\InviteOutboxEvent;
use Illuminate\Support\Carbon;

class InviteRealtimeStreamService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildStreamDeltas(string $userId, ?string $lastEventId): array
    {
        $since = $this->parseSince($lastEventId);
        if ($since === null) {
            return [];
        }

        return InviteOutboxEvent::query()
            ->where('receiver_user_id', $userId)
            ->where('available_at', '>', $since)
            ->orderBy('available_at')
            ->limit(100)
            ->get()
            ->map(static function (InviteOutboxEvent $event): array {
                $payload = is_array($event->payload) ? $event->payload : [];
                $payload['updated_at'] ??= $event->available_at?->toISOString();

                return $payload;
            })
            ->values()
            ->all();
    }

    private function parseSince(?string $lastEventId): ?Carbon
    {
        if ($lastEventId === null || trim($lastEventId) === '') {
            return Carbon::createFromTimestamp(0);
        }

        try {
            return Carbon::parse($lastEventId);
        } catch (\Throwable) {
            return null;
        }
    }
}
