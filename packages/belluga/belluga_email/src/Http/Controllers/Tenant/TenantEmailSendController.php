<?php

declare(strict_types=1);

namespace Belluga\Email\Http\Controllers\Tenant;

use Belluga\Email\Application\TenantEmailDeliveryService;
use Belluga\Email\Exceptions\EmailDeliveryException;
use Belluga\Email\Exceptions\EmailIntegrationPendingException;
use Belluga\Email\Http\Requests\TenantEmailSendRequest;
use Illuminate\Http\JsonResponse;

class TenantEmailSendController
{
    public function __construct(
        private readonly TenantEmailDeliveryService $deliveryService,
    ) {}

    public function __invoke(TenantEmailSendRequest $request): JsonResponse
    {
        try {
            $result = $this->deliveryService->sendTesterWaitlistLead($request->validated());

            return response()->json([
                'ok' => true,
                'provider' => $result['provider'],
                'message_id' => $result['message_id'],
            ]);
        } catch (EmailIntegrationPendingException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 503);
        } catch (EmailDeliveryException $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 502);
        }
    }
}
