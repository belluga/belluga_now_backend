<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Http\Controllers\Controller;
use App\Models\Tenants\AccountProfile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AccountProfileMediaController extends Controller
{
    public function __construct(
        private readonly AccountProfileMediaService $mediaService
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
        $profile = $this->findProfileOrFail($accountProfileId);
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

    private function findProfileOrFail(string $profileId): AccountProfile
    {
        $profile = AccountProfile::query()->find($profileId);

        if (! $profile) {
            try {
                $profile = AccountProfile::query()
                    ->where('_id', new ObjectId($profileId))
                    ->first();
            } catch (\Throwable $exception) {
                $profile = null;
            }
        }

        if (! $profile) {
            throw (new ModelNotFoundException())->setModel(AccountProfile::class, [$profileId]);
        }

        return $profile;
    }
}
