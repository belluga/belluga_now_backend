<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Controllers\Concerns;

use Belluga\Ticketing\Support\TicketingDomainException;
use Illuminate\Http\JsonResponse;

trait HandlesTicketingDomainExceptions
{
    /**
     * @param  callable():array<string,mixed>  $callback
     */
    private function runWithDomainGuard(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (TicketingDomainException $exception) {
            return response()->json([
                'status' => 'rejected',
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }
    }
}
