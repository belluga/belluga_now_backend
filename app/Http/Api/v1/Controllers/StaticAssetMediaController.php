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

    public function avatar(Request $request, string $static_asset_id): Response
    {
        return $this->serve($request, $static_asset_id, 'avatar');
    }

    public function cover(Request $request, string $static_asset_id): Response
    {
        return $this->serve($request, $static_asset_id, 'cover');
    }

    private function serve(Request $request, string $staticAssetId, string $kind): Response
    {
        $asset = $this->assetQueryService->findOrFail($staticAssetId);
        $path = $this->mediaService->resolveMediaPath($asset, $kind);

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
