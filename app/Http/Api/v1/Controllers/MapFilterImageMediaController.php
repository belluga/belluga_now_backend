<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Media\MapFilterImageStorageService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class MapFilterImageMediaController extends Controller
{
    public function __construct(
        private readonly MapFilterImageStorageService $storageService,
    ) {}

    public function show(Request $request): Response
    {
        $key = $request->route('key');
        if (! is_string($key) || trim($key) === '') {
            abort(404);
        }

        $path = $this->storageService->resolveMediaPathForBaseUrl(
            $key,
            $request->getSchemeAndHttpHost(),
        );

        if ($path === null) {
            abort(404);
        }

        $absolutePath = Storage::disk('public')->path($path);
        $lastModifiedTimestamp = filemtime($absolutePath);
        $lastModified = \DateTime::createFromFormat('U', (string) $lastModifiedTimestamp);
        $fingerprint = @md5_file($absolutePath);
        $etag = '"'.($fingerprint !== false && $fingerprint !== ''
            ? $fingerprint
            : md5($path.'|'.$lastModifiedTimestamp)).'"';

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
