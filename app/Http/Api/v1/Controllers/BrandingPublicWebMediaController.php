<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Branding\BrandingPublicWebMediaService;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class BrandingPublicWebMediaController extends Controller
{
    public function __construct(
        private readonly BrandingPublicWebMediaService $mediaService,
    ) {}

    public function defaultImage(Request $request): Response
    {
        $brandable = $this->resolveBrandable((string) $request->route('branding_subject_id'));
        $path = $this->mediaService->resolveMediaPathForBaseUrl(
            $brandable,
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

    private function resolveBrandable(string $brandableId): Tenant|Landlord
    {
        $normalizedId = trim($brandableId);
        if ($normalizedId === '') {
            abort(404);
        }

        $tenant = Tenant::current();
        if ($tenant !== null && (string) $tenant->_id === $normalizedId) {
            return $tenant->fresh() ?? $tenant;
        }

        $landlord = Landlord::singleton();
        if ((string) $landlord->_id === $normalizedId) {
            return $landlord->fresh() ?? $landlord;
        }

        abort(404);
    }
}
