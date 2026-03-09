<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileFormatterService;
use App\Application\Accounts\AccountOnboardingService;
use App\Application\Accounts\AccountQueryService;
use App\Http\Api\v1\Requests\AccountOnboardingStoreRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AccountOnboardingsController extends Controller
{
    public function __construct(
        private readonly AccountOnboardingService $onboardingService,
        private readonly AccountQueryService $accountQueryService,
        private readonly AccountProfileFormatterService $profileFormatter,
    ) {}

    public function store(AccountOnboardingStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $actor = $request->user();

        if ($actor) {
            $validated['created_by'] = (string) $actor->_id;
            $validated['created_by_type'] = $actor instanceof \App\Models\Landlord\LandlordUser
                ? 'landlord'
                : 'tenant';
            $validated['updated_by'] = (string) $actor->_id;
            $validated['updated_by_type'] = $validated['created_by_type'];
        }

        $result = $this->onboardingService->create($validated, $request);

        return response()->json([
            'data' => [
                'account' => $this->accountQueryService->format($result['account']),
                'account_profile' => $this->profileFormatter->format($result['account_profile']),
                'role' => $result['role'],
            ],
        ], 201);
    }
}
