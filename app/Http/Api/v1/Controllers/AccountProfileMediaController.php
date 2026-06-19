<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileGalleryService;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AccountProfileMediaController extends Controller
{
    public function __construct(
        private readonly AccountProfileMediaService $mediaService,
        private readonly AccountProfileQueryService $profileQueryService,
        private readonly AccountProfileGalleryService $galleryService,
    ) {}

    public function avatar(Request $request): Response
    {
        return $this->serve($request, 'avatar');
    }

    public function cover(Request $request): Response
    {
        return $this->serve($request, 'cover');
    }

    public function gallery(Request $request): Response
    {
        $profileId = $request->route('account_profile_id');
        $itemId = trim((string) $request->route('gallery_item_id', ''));
        if (! is_string($profileId) || trim($profileId) === '' || $itemId === '') {
            abort(404);
        }

        $profile = $this->profileQueryService->findOrFail(trim($profileId));
        if (! $this->profileQueryService->isPubliclyExposed($profile)
            || ! $this->galleryService->isExposedForProfile($profile)) {
            abort(404);
        }
        if (! $this->mediaService->galleryItemExists($profile, $itemId)) {
            abort(404);
        }

        $variant = trim((string) $request->query('variant', $this->mediaService->defaultGalleryVariant()));
        if ($variant === '') {
            $variant = $this->mediaService->defaultGalleryVariant();
        }
        if (! $this->mediaService->isGalleryVariant($variant)) {
            abort(404);
        }

        $path = $this->mediaService->resolveGalleryMediaPathForBaseUrl(
            $profile,
            $itemId,
            $variant,
            $request->getSchemeAndHttpHost(),
        );

        if ($path === null) {
            abort(404);
        }

        return $this->buildFileResponse($request, $path);
    }

    private function serve(Request $request, string $kind): Response
    {
        $profileId = $request->route('account_profile_id');
        if (! is_string($profileId) || trim($profileId) === '') {
            $profileId = $request->route('account_profile');
        }
        if (! is_string($profileId) || trim($profileId) === '') {
            abort(404);
        }

        $accountProfileId = trim($profileId);
        $profile = $this->profileQueryService->findOrFail($accountProfileId);
        $path = $this->mediaService->resolveMediaPathForBaseUrl(
            $profile,
            $kind,
            $request->getSchemeAndHttpHost(),
        );

        if ($path === null) {
            abort(404);
        }

        return $this->buildFileResponse($request, $path);
    }

    private function buildFileResponse(Request $request, string $path): Response
    {
        $absolutePath = Storage::disk('public')->path($path);
        $mtime = filemtime($absolutePath);
        $lastModified = $mtime === false
            ? false
            : \DateTime::createFromFormat('U', (string) $mtime);
        $etag = '"'.md5($path.'|'.($mtime === false ? 'missing' : (string) $mtime)).'"';

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
