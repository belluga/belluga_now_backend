<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Events\EventTypeMediaService;
use App\Http\Controllers\Controller;
use App\Models\Tenants\EventType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class EventTypeMediaController extends Controller
{
    public function __construct(
        private readonly EventTypeMediaService $mediaService,
    ) {}

    public function typeAsset(Request $request): Response
    {
        $typeId = $request->route('event_type_id');
        if (! is_string($typeId) || trim($typeId) === '') {
            $typeId = $request->route('event_type');
        }
        if (! is_string($typeId) || trim($typeId) === '') {
            abort(404);
        }

        /** @var EventType|null $type */
        $type = EventType::query()->find(trim($typeId));
        if (! $type) {
            abort(404);
        }

        $path = $this->mediaService->resolveMediaPathForBaseUrl(
            $type,
            'type_asset',
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
