<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Controllers\Concerns;

use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

trait HandlesInviteDomainExceptions
{
    /**
     * @param  callable():array<string, mixed>|JsonResponse|Response  $callback
     */
    private function runWithDomainGuard(callable $callback): JsonResponse|Response
    {
        try {
            $result = $callback();
            if ($result instanceof JsonResponse || $result instanceof Response) {
                return $result;
            }

            return response()->json($result);
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
