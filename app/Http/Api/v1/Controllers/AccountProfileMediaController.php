<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

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
        private readonly AccountProfileQueryService $profileQueryService
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

        $absolutePath = Storage::disk('public')->path($path);
        $lastModified = \DateTime::createFromFormat('U', (string) filemtime($absolutePath));
        $etag = '"'.md5($path.'|'.filemtime($absolutePath)).'"';

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
