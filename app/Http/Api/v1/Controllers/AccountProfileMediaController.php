<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AccountProfileMediaController extends Controller
{
    public function __construct(
        private readonly AccountProfileMediaService $mediaService,
        private readonly AccountProfileQueryService $profileQueryService
    ) {
    }

    public function avatar(Request $request, string $account_profile_id): Response
    {
        return $this->serve($request, $account_profile_id, 'avatar');
    }

    public function cover(Request $request, string $account_profile_id): Response
    {
        return $this->serve($request, $account_profile_id, 'cover');
    }

    private function serve(Request $request, string $accountProfileId, string $kind): Response
    {
        $profile = $this->profileQueryService->findOrFail($accountProfileId);
        $path = $this->mediaService->resolveMediaPath($profile, $kind);

        if ($path === null) {
            abort(404);
        }

        $absolutePath = Storage::disk('public')->path($path);
        $lastModified = \DateTime::createFromFormat('U', (string) filemtime($absolutePath));
        $etag = '"' . md5($path . '|' . filemtime($absolutePath)) . '"';

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
