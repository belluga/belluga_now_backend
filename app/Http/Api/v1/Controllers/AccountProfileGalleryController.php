<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileFormatterService;
use App\Application\AccountProfiles\AccountProfileGalleryService;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Http\Api\v1\Requests\AccountProfileGalleryUpdateRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class AccountProfileGalleryController extends Controller
{
    public function __construct(
        private readonly AccountProfileQueryService $profileQueryService,
        private readonly AccountProfileGalleryService $galleryService,
        private readonly AccountProfileFormatterService $formatter,
    ) {}

    public function update(
        AccountProfileGalleryUpdateRequest $request,
        string $tenant_domain,
        string $account_profile_id,
    ): JsonResponse {
        $profile = $this->profileQueryService->findOrFail($account_profile_id);
        $actor = $request->user();

        $updatedProfile = $this->galleryService->replace(
            $profile,
            $request->validated()['gallery_groups'] ?? [],
            $request->getSchemeAndHttpHost(),
            $request->allFiles(),
            $actor
                ? [
                    'updated_by' => (string) $actor->getKey(),
                    'updated_by_type' => $actor instanceof \App\Models\Landlord\LandlordUser ? 'landlord' : 'tenant',
                ]
                : [],
            $request->header('X-Request-Id'),
        );

        return response()->json([
            'data' => $this->formatter->format($updatedProfile),
        ]);
    }
}
