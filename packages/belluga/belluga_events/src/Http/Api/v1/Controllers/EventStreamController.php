<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Controllers;

use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Http\Api\v1\Requests\EventStreamRequest;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventStreamController extends Controller
{
    public function __construct(
        private readonly EventQueryService $eventQueryService
    ) {
    }

    public function stream(EventStreamRequest $request): StreamedResponse
    {
        $user = $request->user();
        $userId = $user ? (string) $user->getAuthIdentifier() : null;

        $deltas = $this->eventQueryService->buildStreamDeltas(
            $request->validated(),
            $userId,
            $request->header('Last-Event-ID')
        );

        return response()->stream(function () use ($deltas): void {
            foreach ($deltas as $delta) {
                $updatedAt = $delta['updated_at'] ?? null;
                if ($updatedAt) {
                    echo 'id: ' . $updatedAt . "\n";
                }
                echo 'event: ' . $delta['type'] . "\n";
                echo 'data: ' . json_encode($delta) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
