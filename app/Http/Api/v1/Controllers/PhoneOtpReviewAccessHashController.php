<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Auth\PhoneOtpReviewAccessCodeHasher;
use App\Http\Api\v1\Requests\PhoneOtpReviewAccessHashRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PhoneOtpReviewAccessHashController extends Controller
{
    public function __construct(
        private readonly PhoneOtpReviewAccessCodeHasher $hasher,
    ) {}

    public function __invoke(PhoneOtpReviewAccessHashRequest $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'code_hash' => $this->hasher->make((string) $request->validated('code')),
            ],
        ]);
    }
}
