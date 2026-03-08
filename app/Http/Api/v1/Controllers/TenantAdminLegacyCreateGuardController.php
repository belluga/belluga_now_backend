<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TenantAdminLegacyCreateGuardController extends Controller
{
    private const string ONBOARDING_ENDPOINT = '/admin/api/v1/account_onboardings';

    public function rejectAccountsCreate(): JsonResponse
    {
        return response()->json($this->payload(), 409);
    }

    public function rejectAccountProfilesCreate(): JsonResponse
    {
        return response()->json($this->payload(), 409);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'message' => 'Manual tenant-admin account creation must use account onboarding.',
            'error_code' => 'tenant_admin_onboarding_required',
            'meta' => [
                'use_endpoint' => self::ONBOARDING_ENDPOINT,
            ],
        ];
    }
}

