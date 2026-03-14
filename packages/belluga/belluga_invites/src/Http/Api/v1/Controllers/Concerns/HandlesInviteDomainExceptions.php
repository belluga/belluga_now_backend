<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Controllers\Concerns;

use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Http\JsonResponse;

trait HandlesInviteDomainExceptions
{
    /**
     * @param  callable():array<string, mixed>  $callback
     */
    private function runWithDomainGuard(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (InviteDomainException $exception) {
            return response()->json([
                'status' => 'rejected',
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'payload' => $exception->payload,
            ], $exception->httpStatus);
        }
    }
}
