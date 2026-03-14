<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Controllers;

use Belluga\Invites\Application\Realtime\InviteRealtimeStreamService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InviteRealtimeStreamController extends Controller
{
    public function __construct(
        private readonly InviteRealtimeStreamService $streams,
    ) {}

    public function index(Request $request): StreamedResponse
    {
        $user = $request->user();
        $userId = $user ? (string) $user->getAuthIdentifier() : '';
        $lastEventId = $request->header('Last-Event-ID');
        $deltas = $this->streams->buildStreamDeltas($userId, is_string($lastEventId) ? $lastEventId : null);

        return response()->stream(function () use ($deltas): void {
            foreach ($deltas as $delta) {
                $type = is_string($delta['type'] ?? null) ? (string) $delta['type'] : 'invite.updated';
                $updatedAt = is_string($delta['updated_at'] ?? null) ? (string) $delta['updated_at'] : now()->toISOString();

                echo 'id: '.$updatedAt."\n";
                echo 'event: '.$type."\n";
                echo 'data: '.json_encode($delta)."\n\n";
            }

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
