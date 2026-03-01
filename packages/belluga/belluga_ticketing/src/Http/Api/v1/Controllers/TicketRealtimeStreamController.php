<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Controllers;

use Belluga\Ticketing\Application\Realtime\TicketRealtimeStreamService;
use Belluga\Ticketing\Support\TicketingDomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketRealtimeStreamController extends Controller
{
    public function __construct(
        private readonly TicketRealtimeStreamService $streams,
    ) {
    }

    public function offer(string $scope_type, string $scope_id): StreamedResponse|JsonResponse
    {
        try {
            $payload = $this->streams->offerSnapshot((string) $scope_type, (string) $scope_id);

            return $this->streamEnvelope(
                channel: sprintf('ticketing.v1.offer.%s.%s', (string) $scope_type, (string) $scope_id),
                eventType: 'offer.snapshot',
                payload: $payload,
            );
        } catch (TicketingDomainException $exception) {
            return response()->json([
                'status' => 'rejected',
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }
    }

    public function queue(Request $request, string $scope_type, string $scope_id): StreamedResponse|JsonResponse
    {
        try {
            $user = $request->user();
            $principalId = $user ? (string) $user->getAuthIdentifier() : '';
            if ($principalId === '') {
                return response()->json([
                    'status' => 'rejected',
                    'code' => 'auth_required',
                    'message' => 'auth_required',
                ], 401);
            }

            $payload = $this->streams->queueSnapshot((string) $scope_type, (string) $scope_id, $principalId);

            return $this->streamEnvelope(
                channel: sprintf('ticketing.v1.queue.%s.%s.%s', (string) $scope_type, (string) $scope_id, $principalId),
                eventType: 'queue.snapshot',
                payload: $payload,
            );
        } catch (TicketingDomainException $exception) {
            return response()->json([
                'status' => 'rejected',
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }
    }

    public function hold(Request $request, string $hold_id): StreamedResponse|JsonResponse
    {
        try {
            $user = $request->user();
            $principalId = $user ? (string) $user->getAuthIdentifier() : '';
            if ($principalId === '') {
                return response()->json([
                    'status' => 'rejected',
                    'code' => 'auth_required',
                    'message' => 'auth_required',
                ], 401);
            }

            $payload = $this->streams->holdSnapshot((string) $hold_id, $principalId);

            return $this->streamEnvelope(
                channel: sprintf('ticketing.v1.hold.%s', (string) $hold_id),
                eventType: 'hold.snapshot',
                payload: $payload,
            );
        } catch (TicketingDomainException $exception) {
            return response()->json([
                'status' => 'rejected',
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function streamEnvelope(string $channel, string $eventType, array $payload): StreamedResponse
    {
        $envelope = [
            'version' => 'v1',
            'event_type' => $eventType,
            'occurred_at' => Carbon::now()->toISOString(),
            'correlation_id' => (string) Str::uuid(),
            'payload' => $payload,
        ];

        return response()->stream(function () use ($channel, $envelope): void {
            echo 'id: ' . $envelope['occurred_at'] . "\n";
            echo 'event: ' . $channel . "\n";
            echo 'data: ' . json_encode($envelope) . "\n\n";
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

