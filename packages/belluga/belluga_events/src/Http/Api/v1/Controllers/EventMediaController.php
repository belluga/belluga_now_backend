<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Controllers;

use Belluga\Events\Application\Events\EventMediaService;
use Belluga\Events\Application\Events\EventQueryService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class EventMediaController extends Controller
{
    public function __construct(
        private readonly EventMediaService $mediaService,
        private readonly EventQueryService $eventQueryService,
    ) {}

    public function cover(Request $request): Response
    {
        return $this->serve($request, 'cover');
    }

    private function serve(Request $request, string $kind): Response
    {
        $eventId = $request->route('event_id');
        if (! is_string($eventId) || trim($eventId) === '') {
            $eventId = $request->route('event');
        }
        if (! is_string($eventId) || trim($eventId) === '') {
            abort(404);
        }

        $event = $this->eventQueryService->findByIdOrSlug(trim($eventId));
        if (! $event) {
            abort(404);
        }

        $path = $this->mediaService->resolveMediaPathForBaseUrl(
            $event,
            $kind,
            $request->getSchemeAndHttpHost(),
        );

        if ($path === null) {
            abort(404);
        }

        $absolutePath = Storage::disk('public')->path($path);
        $lastModifiedTimestamp = filemtime($absolutePath);
        $lastModified = \DateTime::createFromFormat('U', (string) $lastModifiedTimestamp);
        $etag = '"'.md5($path.'|'.$lastModifiedTimestamp).'"';

        $response = response()->file($absolutePath);
        $response->setPublic();
        $response->setEtag($etag);
        if ($lastModified !== false) {
            $response->setLastModified($lastModified);
        }

        if ($response->isNotModified($request)) {
            return $response->setNotModified();
        }

        return $response;
    }
}
