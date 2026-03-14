<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\StaticAssets\StaticAssetMediaService;
use App\Application\StaticAssets\StaticAssetQueryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class StaticAssetMediaController extends Controller
{
    public function __construct(
        private readonly StaticAssetMediaService $mediaService,
        private readonly StaticAssetQueryService $assetQueryService,
    ) {}

    public function avatar(Request $request): Response
    {
        return $this->serve($request, 'avatar');
    }

    public function cover(Request $request): Response
    {
        return $this->serve($request, 'cover');
    }

    private function serve(Request $request, string $kind): Response
    {
        $assetId = $request->route('static_asset_id');
        if (! is_string($assetId) || trim($assetId) === '') {
            $assetId = $request->route('static_asset');
        }
        if (! is_string($assetId) || trim($assetId) === '') {
            abort(404);
        }

        $staticAssetId = trim($assetId);
        $asset = $this->assetQueryService->findOrFail($staticAssetId);
        $path = $this->mediaService->resolveMediaPathForBaseUrl(
            $asset,
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
