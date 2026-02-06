<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Map\MapPoiQueryService;
use App\Http\Api\v1\Requests\MapPoiStreamRequest;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MapPoiStreamController extends Controller
{
    public function __construct(
        private readonly MapPoiQueryService $queryService
    ) {
    }

    public function stream(MapPoiStreamRequest $request): StreamedResponse
    {
        $deltas = $this->queryService->buildStreamDeltas(
            $request->validated(),
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
